<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttendanceSource;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\HolidayType;
use App\Enums\LeaveDayPortion;
use App\Enums\LeaveRequestStatus;
use App\Enums\PanelType;
use App\Enums\PaymentMethod;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\PayrollRunType;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Models\Branch;
use App\Models\Hr\Attendance;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\WorkShift;
use App\Models\User;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\AttendanceSummaryService;
use App\Services\Hr\DepartmentService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\HolidayService;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveRequestService;
use App\Services\Hr\PayrollRunService;
use App\Services\Hr\SalaryComponentService;
use App\Services\Hr\SalaryStructureService;
use App\Services\Hr\WorkCalendarService;
use App\Services\Hr\WorkShiftService;
use App\Support\Format;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HR demo data for the staff accounts of DemoUserSeeder, so the HR screens have something to show.
 *
 * Without it every `/admin/my/*` screen answers 404 (the signed-in user has no employee record, which
 * is the self-service area's deliberate answer), and attendance, leave and payslips are empty lists.
 *
 * What it writes — **every row through the real HR services**, never an insert that skips a rule:
 *
 *   · the four standard leave types (LeaveTypeSeeder), three departments, a designation per role, the
 *     default work shift, four salary components and a public holiday in the payroll month;
 *   · an employee record linked to every `@myoffice.test` account whose role opens the admin panel —
 *     the Super Admin, admin@myoffice.test and every staff role of DemoUserSeeder. Teacher, student,
 *     client and collaborator accounts are deliberately left alone: they are not staff;
 *   · this leave year's balances (`LeaveBalanceService::grantYear`), a salary structure each;
 *   · attendance punches for every working day of the previous month and of the past week, then the
 *     day close that marks weekly offs and absences;
 *   · a regular payroll run for the previous month taken generated → locked → paid, so every employee
 *     has a payslip;
 *   · two leave requests: one approved (by the HR account), one still pending.
 *
 * **Local / demo only.** It refuses to run with APP_ENV=production, with no override — the rows carry
 * real slip numbers and real audit trails and are indistinguishable from real payroll a week later.
 *
 * **Idempotent.** Every row is matched on its natural key (codes, `employees.user_id`, the attendance
 * date, the payroll period, the leave request's employee + type + first day) and skipped when present,
 * so a second run adds only what is missing. A locked payroll month is never touched again.
 *
 *     php artisan db:seed --class=HrDemoSeeder --force
 *
 * It is deliberately **not** called from DatabaseSeeder or DemoSeeder.
 */
final class HrDemoSeeder extends Seeder
{
    use WritesToConsole;

    /** Deterministic punches, so two fresh installs show the same late marks. */
    private const SEED = 20260930;

    /** Departments: code => [name, description]. */
    private const DEPARTMENTS = [
        'MGT' => ['Management', 'Directors and general management.'],
        'TECH' => ['Technology', 'Software delivery: project management, development, design and web.'],
        'OPS' => ['Operations', 'HR, accounts, front office, support, sales and the training institute.'],
    ];

    /**
     * Role name => [department code, designation code, designation title, level, basic salary, reports-to role].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: string, 5: string|null}>
     */
    private const ROLES = [
        'Super Admin' => ['MGT', 'CEO', 'Chief Executive Officer', 1, '350000.00', null],
        'Admin' => ['MGT', 'GM', 'General Manager', 2, '250000.00', 'Super Admin'],
        'HR' => ['OPS', 'HRM', 'HR Manager', 3, '120000.00', 'Admin'],
        'Accountant' => ['OPS', 'ACC', 'Accountant', 4, '90000.00', 'Admin'],
        'Institute Manager' => ['OPS', 'INM', 'Institute Manager', 3, '130000.00', 'Admin'],
        'Course Coordinator' => ['OPS', 'CCO', 'Course Coordinator', 5, '70000.00', 'Institute Manager'],
        'Sales Executive' => ['OPS', 'SAL', 'Sales Executive', 5, '65000.00', 'Admin'],
        'Receptionist' => ['OPS', 'REC', 'Receptionist', 6, '45000.00', 'HR'],
        'Support Agent' => ['OPS', 'SUP', 'Support Executive', 6, '55000.00', 'HR'],
        'Project Manager' => ['TECH', 'PM', 'Project Manager', 3, '180000.00', 'Admin'],
        'Developer' => ['TECH', 'DEV', 'Software Developer', 4, '140000.00', 'Project Manager'],
        'Designer' => ['TECH', 'DES', 'UI/UX Designer', 4, '110000.00', 'Project Manager'],
        'Website Manager' => ['TECH', 'WEB', 'Website Manager', 4, '100000.00', 'Project Manager'],
        'Digital Marketer' => ['TECH', 'DMK', 'Digital Marketing Executive', 5, '80000.00', 'Project Manager'],
        'SEO Expert' => ['TECH', 'SEO', 'SEO Specialist', 5, '85000.00', 'Project Manager'],
    ];

    /** Any admin-panel role not named above (a role somebody added on the screen). */
    private const FALLBACK = ['OPS', 'STF', 'Staff Member', 6, '60000.00', 'Admin'];

    /** Heads: department code => role whose employee heads it. */
    private const HEADS = ['MGT' => 'Super Admin', 'TECH' => 'Project Manager', 'OPS' => 'HR'];

    /** code => [name, group, calculation, default amount, default rate, print label]. */
    private const COMPONENTS = [
        'HRA' => ['House Rent Allowance', SalaryComponentGroup::Allowance, SalaryComponentCalculation::PercentageOfBasic, '0.00', '40.0000', 'House rent'],
        'MED' => ['Medical Allowance', SalaryComponentGroup::Allowance, SalaryComponentCalculation::Fixed, '5000.00', '0.0000', 'Medical'],
        'CONV' => ['Conveyance Allowance', SalaryComponentGroup::Allowance, SalaryComponentCalculation::Fixed, '6000.00', '0.0000', 'Conveyance'],
        'EOBI' => ['EOBI Contribution', SalaryComponentGroup::Statutory, SalaryComponentCalculation::Fixed, '370.00', '0.0000', 'EOBI'],
    ];

    private User $actor;

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, Designation> */
    private array $designations = [];

    /** @var array<string, Employee> role name => employee */
    private array $byRole = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function run(): void
    {
        $this->assertNotProduction();

        $this->actor = $this->resolveActor();

        // Same runtime overrides as DemoSeeder: nothing queued is left for a worker nobody runs, and no
        // notification a leave or payroll step fires can reach a real mailbox.
        config(['queue.default' => 'sync']);
        config(['mail.default' => 'log']);

        // `setUser()`, not `login()`: Blameable needs a user, not a sign-in that never happened.
        Auth::setUser($this->actor);

        try {
            $this->call(LeaveTypeSeeder::class);

            $this->seedDepartments();
            $shift = $this->seedWorkShift();
            $components = $this->seedSalaryComponents();
            $this->seedHoliday();

            $employees = $this->seedEmployees($shift);

            if ($employees->isEmpty()) {
                $this->seedWarning('HR demo: no @myoffice.test staff accounts found — run DemoUserSeeder first.');

                return;
            }

            $this->seedHeads();
            $this->seedLeaveBalances();
            $this->seedSalaryStructures($employees, $components);
            $this->seedPayroll($employees);
            $this->seedRecentAttendance($employees);
            $this->seedLeaveRequests();
        } finally {
            Auth::forgetUser();
        }

        $this->seedInfo('HR demo data ready.');
        $this->seedTable(['What', 'Written this run'], collect($this->counts)
            ->map(fn (int $count, string $what): array => [$what, (string) $count])
            ->values()
            ->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    private function assertNotProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        throw new RuntimeException(
            'HrDemoSeeder refuses to run with APP_ENV=production. Demo employees, salary slips and leave '
            .'in a production database are indistinguishable from real payroll within a week. There is no '
            .'flag that overrides this; use a staging database.'
        );
    }

    private function resolveActor(): User
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        $actor = User::query()
            ->whereHas('roles', fn ($q) => $q
                ->where('name', User::SUPER_ADMIN_ROLE)
                ->where('guard_name', $guard))
            ->orderBy('id')
            ->first();

        if ($actor === null) {
            throw new RuntimeException(
                'No Super Admin account exists, so there is nobody to attribute the HR demo rows to. Run '
                .'`php artisan user:create-super-admin --name="..." --email="..."` first.'
            );
        }

        return $actor;
    }

    /*
    |--------------------------------------------------------------------------
    | Organisation
    |--------------------------------------------------------------------------
    */

    private function seedDepartments(): void
    {
        $service = app(DepartmentService::class);

        foreach (self::DEPARTMENTS as $code => [$name, $description]) {
            $department = Department::withTrashed()->where('code', $code)->first();

            if ($department === null) {
                $department = $service->store([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'is_active' => true,
                    'sort_order' => count($this->departments) * 10,
                ]);
                $this->bump('departments');
            }

            $this->departments[$code] = $department;
        }

        foreach (array_merge(array_values(self::ROLES), [self::FALLBACK]) as [$deptCode, $code, $title, $level]) {
            $designation = Designation::withTrashed()->where('code', $code)->first();

            if ($designation === null) {
                $designation = $service->storeDesignation([
                    'department_id' => $this->departments[$deptCode]->getKey(),
                    'code' => $code,
                    'title' => $title,
                    'level' => $level,
                    'is_active' => true,
                    'sort_order' => $level * 10,
                ]);
                $this->bump('designations');
            }

            $this->designations[$code] = $designation;
        }
    }

    private function seedWorkShift(): WorkShift
    {
        $shift = WorkShift::withTrashed()->where('code', 'GEN')->first()
            ?? WorkShift::query()->where('is_default', true)->where('is_active', true)->first();

        if ($shift !== null) {
            return $shift;
        }

        $this->bump('work shifts');

        return app(WorkShiftService::class)->store([
            'code' => 'GEN',
            'name' => 'General (09:00 - 18:00)',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_minutes' => 60,
            'grace_in_minutes' => 15,
            'grace_out_minutes' => 10,
            'weekly_off_days' => ['saturday', 'sunday'],
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    /**
     * @return Collection<string, SalaryComponent>
     */
    private function seedSalaryComponents(): Collection
    {
        $service = app(SalaryComponentService::class);
        $components = collect();
        $sort = 10;

        foreach (self::COMPONENTS as $code => [$name, $group, $calculation, $amount, $rate, $label]) {
            $component = SalaryComponent::withTrashed()->where('code', $code)->first();

            if ($component === null) {
                $component = $service->store([
                    'code' => $code,
                    'name' => $name,
                    'component_group' => $group->value,
                    'calculation_type' => $calculation->value,
                    'default_amount' => $amount,
                    'default_rate' => $rate,
                    'affects_gross' => true,
                    'is_statutory' => $group === SalaryComponentGroup::Statutory,
                    'is_attendance_dependent' => false,
                    'print_label' => $label,
                    'is_active' => true,
                    'sort_order' => $sort,
                ]);
                $this->bump('salary components');
            }

            $components->put($code, $component);
            $sort += 10;
        }

        return $components;
    }

    /**
     * Independence Day in the payroll month, so the slips show a paid holiday rather than a flat month.
     */
    private function seedHoliday(): void
    {
        $date = $this->payrollMonth()->setDay(14);

        if ($date->month !== 8 || Holiday::query()->whereDate('holiday_date', $date->toDateString())->exists()) {
            return;
        }

        app(HolidayService::class)->createRange([
            'title' => 'Independence Day',
            'holiday_type' => HolidayType::Public->value,
            'is_paid' => true,
            'is_recurring_yearly' => true,
            'is_active' => true,
            'description' => 'National holiday.',
        ], $date);

        $this->bump('holidays');
    }

    /**
     * One employee per `@myoffice.test` account whose role opens the admin panel.
     *
     * @return Collection<int, Employee>
     */
    private function seedEmployees(WorkShift $shift): Collection
    {
        $service = app(EmployeeService::class);
        $defaultBranch = Branch::default()?->getKey();

        $users = User::query()
            ->with('roles')
            ->where('email', 'like', '%@'.DemoUserSeeder::DEMO_DOMAIN)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->staffRoleOf($user) !== null)
            ->values();

        $employees = collect();

        // Managers first, so `reports_to_id` always points at somebody who already exists.
        $ordered = $users->sortBy(fn (User $user): int => array_search(
            $this->staffRoleOf($user),
            array_keys(self::ROLES),
            true
        ) === false ? 999 : (int) array_search($this->staffRoleOf($user), array_keys(self::ROLES), true));

        foreach ($ordered as $index => $user) {
            $role = (string) $this->staffRoleOf($user);
            [$deptCode, $designationCode, , , , $reportsTo] = self::ROLES[$role] ?? self::FALLBACK;

            $employee = Employee::withTrashed()->where('user_id', $user->getKey())->first();

            if ($employee === null) {
                $employee = DB::transaction(function () use ($service, $user, $deptCode, $designationCode, $reportsTo, $shift, $defaultBranch, $index): Employee {
                    $employee = $service->create([
                        'name' => $user->name,
                        'email' => $user->email,
                        'branch_id' => $user->branch_id ?? $defaultBranch,
                        'department_id' => $this->departments[$deptCode]->getKey(),
                        'designation_id' => $this->designations[$designationCode]->getKey(),
                        'work_shift_id' => $shift->getKey(),
                        'reports_to_id' => $reportsTo === null ? null : ($this->byRole[$reportsTo] ?? null)?->getKey(),
                        // Joined before the current leave year, so the demo shows full-year grants.
                        'joining_date' => Carbon::create(2025, 1, 6)->subWeeks(((int) $index) * 3)->toDateString(),
                        'employment_type' => EmploymentType::FullTime->value,
                        'status' => EmployeeStatus::Active,
                        'city' => 'Lahore',
                        'notes' => 'Demo employee (HrDemoSeeder).',
                    ]);

                    return $service->linkUser($employee, $user);
                });

                $this->bump('employees');
            }

            $this->byRole[$role] ??= $employee;
            $employees->push($employee);
        }

        foreach ($this->departments as $department) {
            app(DepartmentService::class)->recountEmployees($department);
        }

        return $employees;
    }

    private function seedHeads(): void
    {
        $service = app(DepartmentService::class);

        foreach (self::HEADS as $code => $role) {
            $department = $this->departments[$code]->fresh();
            $head = $this->byRole[$role] ?? null;

            if ($department === null || $head === null || $department->head_employee_id !== null) {
                continue;
            }

            $service->setHead($department, $head);
        }
    }

    private function seedLeaveBalances(): void
    {
        $service = app(LeaveBalanceService::class);
        $year = $service->leaveYearFor(now())['year'];

        $this->bump('leave balance grants', $service->grantYear($year));
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<string, SalaryComponent>  $components
     */
    private function seedSalaryStructures(Collection $employees, Collection $components): void
    {
        $service = app(SalaryStructureService::class);

        foreach ($employees as $employee) {
            if (SalaryStructure::query()->where('employee_id', $employee->getKey())->exists()) {
                continue;
            }

            $role = (string) $this->staffRoleOf($employee->user()->with('roles')->first());
            $basic = (self::ROLES[$role] ?? self::FALLBACK)[4];

            $service->createVersion(
                employee: $employee,
                effectiveFrom: $employee->joining_date->copy(),
                basicSalary: $basic,
                components: $components->values()
                    ->map(fn (SalaryComponent $component, int $i): array => [
                        'salary_component_id' => (int) $component->getKey(),
                        'sort_order' => $i,
                    ])
                    ->all(),
                reason: 'Starting salary (demo data).',
                actorId: (int) $this->actor->getKey(),
            );

            $this->bump('salary structures');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance and payroll
    |--------------------------------------------------------------------------
    */

    /**
     * Previous month: punches → day close → summaries → run generated → locked → every slip paid.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function seedPayroll(Collection $employees): void
    {
        $month = $this->payrollMonth();
        $service = app(PayrollRunService::class);

        $run = PayrollRun::query()
            ->where('period_year', $month->year)
            ->where('period_month', $month->month)
            ->where('run_type', PayrollRunType::Regular)
            ->whereNull('branch_id')
            ->where('status', '<>', PayrollRunStatus::Cancelled)
            ->first();

        // A locked month is evidence: its attendance and summaries are frozen, so only paying is left.
        if ($run === null || $run->status->isEditable()) {
            $this->punchRange($employees, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay());
            $this->bump('attendance summaries', app(AttendanceSummaryService::class)->buildPeriod($month->year, $month->month));
        }

        if ($run === null) {
            $run = $service->create(
                year: $month->year,
                month: $month->month,
                paymentDate: $month->copy()->addMonth()->startOfMonth(),
                actor: $this->actor,
            );
            $this->bump('payroll runs');
        }

        if ($run->status->isEditable()) {
            $report = $service->generate($run, null, $this->actor);
            $this->bump('payslips generated', $report['generated']);

            foreach ($report['skipped'] as $employeeId => $reason) {
                $this->seedWarning(sprintf('Payroll: employee #%d skipped (%s).', $employeeId, $reason));
            }

            $run = $service->lock($run->fresh(), $this->actor);
        }

        $paidOn = $month->copy()->addMonth()->startOfMonth()->setTime(11, 0);

        foreach ($run->items()->with('run')->get() as $item) {
            if ($item->status !== PayrollItemStatus::Locked) {
                continue;
            }

            $service->markItemPaid(
                $item,
                PaymentMethod::BankTransfer,
                'DEMO-'.$item->slip_number,
                $this->actor,
                $paidOn,
            );
            $this->bump('payslips paid');
        }
    }

    /**
     * The past week (yesterday and the six days before), so the attendance screens are not empty today.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function seedRecentAttendance(Collection $employees): void
    {
        $to = now()->subDay()->startOfDay();
        $from = $to->copy()->subDays(6);

        // Never reach back into a month a payroll run has already locked.
        if ($from->lessThan($this->payrollMonth()->endOfMonth())) {
            $from = $this->payrollMonth()->addMonth()->startOfMonth();
        }

        if ($from->greaterThan($to)) {
            return;
        }

        $this->punchRange($employees, $from, $to);
    }

    /**
     * Punch every employee in on every working day of the range, then close each day so weekly offs,
     * holidays and the odd absence get their rows too.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function punchRange(Collection $employees, Carbon $from, Carbon $to): void
    {
        $attendance = app(AttendanceService::class);
        $calendar = app(WorkCalendarService::class);
        $calendar->preload($from, $to);

        foreach ($calendar->datesIn($from, $to) as $date) {
            foreach ($employees as $employee) {
                $employee = $employee->fresh();

                if ($employee->joining_date->greaterThan($date)
                    || ! $calendar->dayTypeFor($employee, $date)->countsTowardWorkingDays()
                    || $this->isDemoAbsence($employee, $date)
                ) {
                    continue;
                }

                $existing = Attendance::query()
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('attendance_date', $date->toDateString())
                    ->first();

                if ($existing !== null && ($existing->check_in_at !== null || $existing->isLocked() || $existing->is_manual)) {
                    continue;
                }

                $noise = crc32(self::SEED.'|'.$employee->getKey().'|'.$date->toDateString());
                // Punches are instants stored UTC (D61), but the shift is a wall-clock time in the
                // business timezone — so 09:00 means 09:00 in `localization.timezone`, then converted.
                $wallClock = fn (string $time): Carbon => Carbon::parse($date->toDateString().' '.$time, Format::timezone())->utc();

                $in = $wallClock('09:00')->addMinutes(($noise % 36) - 10);
                $out = $wallClock('18:00')->addMinutes(intdiv($noise, 36) % 50);

                $attendance->checkIn($employee, $in, AttendanceSource::Admin, null, $date);
                $attendance->checkOut($employee, $out, AttendanceSource::Admin, null, $date);
                $this->bump('attendance punches');
            }

            $attendance->closeDay($date);
        }
    }

    /**
     * A deterministic handful of absent days, so a slip shows a loss-of-pay line and the attendance
     * screen shows an absence.
     */
    private function isDemoAbsence(Employee $employee, Carbon $date): bool
    {
        return crc32('absent|'.self::SEED.'|'.$employee->getKey().'|'.$date->toDateString()) % 40 === 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Leave
    |--------------------------------------------------------------------------
    */

    private function seedLeaveRequests(): void
    {
        $service = app(LeaveRequestService::class);
        $approver = User::query()->where('email', 'hr@'.DemoUserSeeder::DEMO_DOMAIN)->first() ?? $this->actor;

        $samples = [
            // role, type code, first working day from today, days, reason, approve?
            ['Designer', 'AL', 12, 2, 'Family wedding out of town.', true],
            ['Sales Executive', 'CL', 5, 1, 'Personal errand at the bank.', false],
        ];

        foreach ($samples as [$role, $typeCode, $offset, $days, $reason, $approve]) {
            $employee = $this->byRole[$role] ?? null;
            $type = LeaveType::query()->where('code', $typeCode)->first();

            if ($employee === null || $type === null) {
                continue;
            }

            $from = $this->nextWeekday(now()->startOfDay()->addDays($offset));
            $to = $this->nextWeekday($from->copy()->addDays($days - 1));

            $request = LeaveRequest::query()
                ->where('employee_id', $employee->getKey())
                ->where('leave_type_id', $type->getKey())
                ->whereDate('from_date', $from->toDateString())
                ->first();

            if ($request === null) {
                $request = $service->apply(
                    employee: $employee->fresh()->load(['manager', 'department']),
                    type: $type,
                    from: $from,
                    to: $to,
                    portion: LeaveDayPortion::FullDay,
                    reason: $reason,
                    contact: null,
                    actor: $employee->user,
                );
                $this->bump('leave requests');
            }

            if ($approve && $request->status === LeaveRequestStatus::Pending
                && (int) $employee->user_id !== (int) $approver->getKey()) {
                $service->approveLevel($request, $approver, 'Approved (demo data).');
                $this->bump('leave approvals');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** The first day of the month before this one — the payroll month. */
    private function payrollMonth(): Carbon
    {
        return now()->startOfMonth()->subMonthNoOverflow()->startOfDay();
    }

    private function nextWeekday(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * The admin-panel role this account is seeded as, or null for a non-staff account.
     */
    private function staffRoleOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach ($user->roles as $role) {
            if ($role->panelType() === PanelType::Admin) {
                return (string) $role->name;
            }
        }

        return null;
    }

    private function bump(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }
}
