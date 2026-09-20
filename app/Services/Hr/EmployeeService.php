<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveLedgerReason;
use App\Enums\LeaveRequestStatus;
use App\Enums\SalaryStructureStatus;
use App\Enums\UserStatus;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeSkill;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\Hr\SalaryStructure;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Employees: hiring, the org chart, status, and leaving (phase-07 §2.4, §2.26, §6.2, requirement §24).
 *
 * **`employee_code` is issued once and never reissued.** It is printed on slips and quoted in letters, so
 * a changed code would orphan paper that already exists. `user_id` is the one crossing between the
 * organisational world (duties, reporting lines, approvers — all `employees.id`) and the acting world
 * (who did something, who is assigned work — all `users.id`), per D32.
 *
 * **Leaving is a sequence, not a flag** (§2.26). `exit()` stamps the exit, cancels the pending leave,
 * closes the salary structure at the exit date, posts the encashable balance as an `exit_settlement`
 * ledger entry, leaves every advance outstanding for the final-settlement screen, and ends the login's
 * session by setting the user inactive. Doing any one of those by hand is how a leaver stays on payroll.
 *
 * **A re-hire is a new row with a new code.** The old record stays exactly as it was, because it is the
 * evidence behind payroll history that has already been paid.
 */
class EmployeeService
{
    /**
     * Which statuses each status may move to (§2.26). `resigned` and `terminated` are terminal.
     *
     * @var array<string, list<EmployeeStatus>>
     */
    private const TRANSITIONS = [
        'probation' => [EmployeeStatus::Active, EmployeeStatus::Inactive, EmployeeStatus::Terminated],
        'active' => [
            EmployeeStatus::Suspended,
            EmployeeStatus::Inactive,
            EmployeeStatus::Resigned,
            EmployeeStatus::Terminated,
        ],
        'suspended' => [EmployeeStatus::Active, EmployeeStatus::Inactive, EmployeeStatus::Terminated],
        'inactive' => [EmployeeStatus::Active],
        'resigned' => [],
        'terminated' => [],
    ];

    public function __construct(
        private readonly HrNumberService $numbers,
        private readonly LeaveBalanceService $balances,
    ) {}

    /**
     * Hire somebody (§6.2).
     *
     * When a login is asked for, the `users` row is created in the **same transaction** with
     * `must_change_password` set and whatever role the caller chose — never a hardcoded one, because the
     * role a new employee gets is a business decision and not a constant in this file.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $account  `['name','email','password','role']`
     */
    public function create(array $data, ?array $account = null): Employee
    {
        return DB::transaction(function () use ($data, $account): Employee {
            $employee = new Employee;
            $employee->fill($data);
            $employee->forceFill([
                'employee_code' => $this->numbers->employeeCode(),
                'status' => $data['status'] ?? EmployeeStatus::Active,
                'status_changed_at' => now(),
            ]);

            if ($account !== null) {
                $employee->forceFill(['user_id' => $this->createAccount($account, $employee)->getKey()]);
            }

            $this->persist($employee);

            return $employee;
        });
    }

    /**
     * Edit an employee. `employee_code`, `user_id`, the status columns and `current_gross_salary` are not
     * reachable from here — each has its own method, because each has its own consequences.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        unset($data['employee_code'], $data['user_id'], $data['status'], $data['exit_date'], $data['current_gross_salary']);

        return DB::transaction(function () use ($employee, $data): Employee {
            $employee->fill($data);

            $this->persist($employee);

            return $employee;
        });
    }

    /**
     * Move an employee between the states of §2.26.
     */
    public function changeStatus(
        Employee $employee,
        EmployeeStatus $to,
        string $reason,
        ?Carbon $exitDate = null,
    ): Employee {
        $from = $employee->status;

        if ($from === $to) {
            return $employee;
        }

        if (! in_array($to, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw HrRuleException::refuse('status', sprintf(
                'An employee who is %s cannot become %s. %s',
                $from->label(),
                $to->label(),
                $from->isExited()
                    ? 'A former employee is history; a re-hire is a new record with a new code, so the '
                     .'payroll already paid stays exactly as it was.'
                    : 'The states are fixed (phase-07 §2.26).'
            ));
        }

        if ($to->requiresReason() && trim($reason) === '') {
            throw HrRuleException::reasonRequired('status_reason', sprintf(
                'Say why this employee is becoming %s.',
                $to->label()
            ));
        }

        if ($to->isExited()) {
            return $this->exit($employee, $exitDate ?? now()->startOfDay(), $to, $reason);
        }

        return DB::transaction(function () use ($employee, $to, $reason): Employee {
            $employee->forceFill([
                'status' => $to,
                'status_reason' => trim($reason) ?: null,
                'status_changed_at' => now(),
            ])->save();

            $this->syncAccountStatus($employee, $to);

            return $employee;
        });
    }

    /**
     * The full leaving sequence of §2.26, in one transaction.
     */
    public function exit(Employee $employee, Carbon $exitDate, EmployeeStatus $status, string $reason): Employee
    {
        $exitDate = $exitDate->copy()->startOfDay();

        if (! $status->isExited()) {
            throw HrRuleException::refuse('status', 'An exit is a resignation or a termination.');
        }

        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('exit_reason', 'Say why the employee is leaving.');
        }

        if ($exitDate->lessThan($employee->joining_date)) {
            throw HrRuleException::refuse('exit_date', sprintf(
                'An exit on %s is before the joining date of %s.',
                $exitDate->toDateString(),
                $employee->joining_date->toDateString()
            ));
        }

        return DB::transaction(function () use ($employee, $exitDate, $status, $reason): Employee {
            $employee->forceFill([
                'status' => $status,
                'status_reason' => trim($reason),
                'status_changed_at' => now(),
                'exit_date' => $exitDate->toDateString(),
                'exit_reason' => trim($reason),
            ])->save();

            $this->cancelPendingLeave($employee);
            $this->closeSalaryStructure($employee, $exitDate);
            $this->settleEncashableLeave($employee, $exitDate);
            $this->syncAccountStatus($employee, $status);

            return $employee;
        });
    }

    /**
     * Attach a login to an employee record (D32). Refused when the login already belongs to somebody
     * else, naming them — that is always a mistake and never an intention.
     */
    public function linkUser(Employee $employee, User $user): Employee
    {
        $taken = Employee::query()
            ->where('user_id', $user->getKey())
            ->where('id', '<>', $employee->getKey())
            ->first();

        if ($taken !== null) {
            throw HrRuleException::refuse('user_id', sprintf(
                'That login already belongs to %s (%s). One login, one employee.',
                $taken->name,
                $taken->employee_code
            ));
        }

        $employee->forceFill(['user_id' => $user->getKey()])->save();

        return $employee;
    }

    /**
     * Detach the login. The account itself is untouched — unlinking is an HR act, not a deletion.
     */
    public function unlinkUser(Employee $employee): Employee
    {
        $employee->forceFill(['user_id' => null])->save();

        return $employee;
    }

    /**
     * Replace an employee's skills, keeping the rows that already match.
     *
     * @param  list<array{name: string, level?: string|null, years_experience?: int|null}>  $skills
     */
    public function syncSkills(Employee $employee, array $skills): void
    {
        DB::transaction(function () use ($employee, $skills): void {
            $seen = [];
            $sort = 0;

            foreach ($skills as $skill) {
                $name = trim((string) ($skill['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $key = mb_strtolower($name);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $row = EmployeeSkill::query()
                    ->withTrashed()
                    ->where('employee_id', $employee->getKey())
                    ->whereRaw('LOWER(`name`) = ?', [$key])
                    ->first() ?? new EmployeeSkill;

                $row->forceFill([
                    'employee_id' => $employee->getKey(),
                    'name' => $name,
                    'level' => $skill['level'] ?? $row->level,
                    'years_experience' => $skill['years_experience'] ?? $row->years_experience,
                    'sort_order' => $sort++,
                    'deleted_at' => null,
                ])->save();
            }

            EmployeeSkill::query()
                ->where('employee_id', $employee->getKey())
                ->when($seen !== [], fn ($query) => $query->whereRaw(
                    'LOWER(`name`) NOT IN ('.implode(',', array_fill(0, count($seen), '?')).')',
                    array_keys($seen)
                ))
                ->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $account
     */
    private function createAccount(array $account, Employee $employee): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $account['name'] ?? $employee->name,
            'email' => $account['email'] ?? $employee->email,
            'password' => $account['password'] ?? null,
            'status' => UserStatus::Active,
            'must_change_password' => true,
        ]);

        $user->save();

        if (! empty($account['role'])) {
            $user->syncRoles([$account['role']]);
        }

        return $user;
    }

    private function persist(Employee $employee): void
    {
        try {
            $employee->save();
        } catch (UniqueConstraintViolationException) {
            throw HrRuleException::refuse('user_id',
                'That login is already linked to another employee. One login belongs to at most one '
                .'employee record (D32).');
        }
    }

    private function syncAccountStatus(Employee $employee, EmployeeStatus $status): void
    {
        if ($employee->user_id === null) {
            return;
        }

        $user = User::query()->find($employee->user_id);

        if ($user === null) {
            return;
        }

        $target = match (true) {
            $status->isExited(), $status === EmployeeStatus::Inactive => UserStatus::Inactive,
            $status === EmployeeStatus::Suspended => UserStatus::Suspended,
            default => UserStatus::Active,
        };

        $user->forceFill(['status' => $target])->save();
    }

    /**
     * Pending leave dies with the employment — approving it afterwards would credit days to somebody who
     * has left.
     */
    private function cancelPendingLeave(Employee $employee): void
    {
        $pending = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', LeaveRequestStatus::Pending)
            ->get();

        foreach ($pending as $request) {
            $request->forceFill([
                'status' => LeaveRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => 'The employee exited.',
            ])->save();

            $request->days()->update(['is_active' => false, 'updated_at' => now()]);

            $this->balances->release($request, LeaveLedgerReason::ReservationRelease);
        }
    }

    private function closeSalaryStructure(Employee $employee, Carbon $exitDate): void
    {
        SalaryStructure::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('effective_to')
            ->whereIn('status', [SalaryStructureStatus::Scheduled, SalaryStructureStatus::Active])
            ->get()
            ->each(function (SalaryStructure $structure) use ($exitDate): void {
                $structure->forceFill([
                    'effective_to' => $exitDate->toDateString(),
                    'status' => SalaryStructureStatus::Expired,
                ])->save();
            });
    }

    /**
     * Post the encashable balance as an `exit_settlement` ledger entry, so the final settlement has a row
     * to point at rather than a figure somebody worked out on paper.
     */
    private function settleEncashableLeave(Employee $employee, Carbon $exitDate): void
    {
        $year = $this->balances->leaveYearFor($exitDate)['year'];

        $balances = LeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employee->getKey())
            ->where('leave_year', $year)
            ->get();

        foreach ($balances as $balance) {
            $type = $balance->leaveType;

            if (! $type instanceof LeaveType || ! $type->is_encashable) {
                continue;
            }

            $remaining = Money::round((string) $balance->available_days, 4);

            if (! Money::isPositive($remaining)) {
                continue;
            }

            $this->balances->adjust(
                employee: $employee,
                type: $type,
                signedDays: Money::negate($remaining),
                note: sprintf('Encashed on exit (%s).', $exitDate->toDateString()),
                on: $exitDate->copy(),
            );
        }
    }
}
