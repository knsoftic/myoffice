<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveLedgerReason;
use App\Enums\LedgerEntryType;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveBalanceTransaction;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Leave days as a ledger, and the balance as a cache over it (phase-07 §6.2, §6.5.4, HR-7).
 *
 * **Every day that moves leaves a row.** A grant, an accrual, a reservation, its release, a consumption, a
 * cancellation, a manual correction, a lapse — each is an append-only entry with a reason and a value
 * date, and `leave_balances` is only a fast answer to "how many days does this person have left?". The
 * cache is re-derivable at any moment, which is what {@see assertConsistent()} checks and what makes an
 * employee's leave statement read like a bank statement rather than like a number somebody typed.
 *
 * The polarity is the same trick in both directions: `signed_days` is `+days` on a credit and `-days` on a
 * debit, additive columns move **with** the sign and subtractive ones move **against** it. Because
 * `available = entitled + carried + accrued + adjusted − consumed − pending − encashed − expired`, that
 * makes `available_days` exactly `SUM(signed_days)` — one identity to check instead of eight.
 *
 * **A reservation is not a consumption.** Applying reserves the days so a second application cannot spend
 * them; approving releases the reservation and consumes them in the **same transaction**, so no moment
 * exists in which the days are counted twice. Rejecting or cancelling releases them.
 */
class LeaveBalanceService
{
    /**
     * Which cached column each reason moves, and whether it moves with the entry's sign.
     *
     * `true` — an additive column (`available` includes it with a `+`).
     * `false` — a subtractive column (`available` subtracts it, so the column moves against the sign).
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const COLUMNS = [
        LeaveLedgerReason::AnnualGrant->value => ['entitled_days', true],
        LeaveLedgerReason::JoiningProration->value => ['entitled_days', true],
        LeaveLedgerReason::MonthlyAccrual->value => ['accrued_days', true],
        LeaveLedgerReason::CarryForwardIn->value => ['carried_forward_days', true],
        LeaveLedgerReason::ManualAdjustment->value => ['adjusted_days', true],
        LeaveLedgerReason::ExitSettlement->value => ['adjusted_days', true],
        LeaveLedgerReason::LeaveConsumed->value => ['consumed_days', false],
        LeaveLedgerReason::LeaveCancelled->value => ['consumed_days', false],
        LeaveLedgerReason::Reservation->value => ['pending_days', false],
        LeaveLedgerReason::ReservationRelease->value => ['pending_days', false],
        LeaveLedgerReason::Encashment->value => ['encashed_days', false],
        LeaveLedgerReason::CarryForwardExpiry->value => ['expired_days', false],
        LeaveLedgerReason::YearEndLapse->value => ['expired_days', false],
    ];

    /*
    |--------------------------------------------------------------------------
    | The leave year
    |--------------------------------------------------------------------------
    */

    /**
     * The leave year a date falls in, as `{year, start, end}`.
     *
     * The year is the one the window **starts** in, so a July-to-June leave year calls the whole thing
     * 2026 rather than being ambiguous for six months of it.
     *
     * @return array{year: int, start: Carbon, end: Carbon}
     */
    public function leaveYearFor(Carbon $on): array
    {
        $startMonth = max(1, min(12, (int) setting('hr.leave_year_start_month', 1)));

        $year = $on->month >= $startMonth ? $on->year : $on->year - 1;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();

        return [
            'year' => $year,
            'start' => $start,
            'end' => $start->copy()->addYear()->subDay()->startOfDay(),
        ];
    }

    /**
     * The window for a named leave year.
     *
     * @return array{year: int, start: Carbon, end: Carbon}
     */
    public function leaveYearWindow(int $year): array
    {
        $startMonth = max(1, min(12, (int) setting('hr.leave_year_start_month', 1)));
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();

        return [
            'year' => $year,
            'start' => $start,
            'end' => $start->copy()->addYear()->subDay()->startOfDay(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Grants and accruals — every one idempotent
    |--------------------------------------------------------------------------
    */

    /**
     * Credit the year's quota, once, for one employee or for everybody (§6.2).
     *
     * Running it twice writes nothing the second time: the ledger already holds the grant, and a
     * double-credited quota is the kind of error nobody notices until somebody takes the extra days.
     *
     * A joiner is **pro-rated** when the type says so — `quota x remaining months / 12`, rounded to four
     * places — and the row records `joining_proration` rather than `annual_grant` so the smaller number
     * has an explanation attached to it.
     */
    public function grantYear(int $leaveYear, ?Employee $employee = null): int
    {
        $window = $this->leaveYearWindow($leaveYear);
        $types = LeaveType::query()->where('is_active', true)->get()
            ->filter(fn (LeaveType $type): bool => $type->accrual_method === LeaveAccrualMethod::AnnualGrant);

        $granted = 0;

        foreach ($this->employeesFor($employee, $window['end']) as $person) {
            foreach ($types as $type) {
                if (! $type->appliesTo($person->employment_type)) {
                    continue;
                }

                $prorated = $type->accrue_from_joining
                    && $person->joining_date->greaterThan($window['start'])
                    && $person->joining_date->lessThanOrEqualTo($window['end']);

                $reason = $prorated ? LeaveLedgerReason::JoiningProration : LeaveLedgerReason::AnnualGrant;

                if ($this->hasEntry($person, $type, $leaveYear, $reason)) {
                    continue;
                }

                $days = (string) $type->annual_quota_days;

                if ($prorated) {
                    $remaining = (string) max(0, 12 - $person->joining_date->diffInMonths($window['start']));
                    $days = Money::round(Money::div(Money::mul($days, $remaining), '12'), 4);
                }

                if (Money::isZero($days)) {
                    continue;
                }

                $this->post(
                    employee: $person,
                    type: $type,
                    leaveYear: $leaveYear,
                    entryType: LedgerEntryType::Credit,
                    reason: $reason,
                    days: $days,
                    occurredOn: $prorated ? $person->joining_date->copy() : $window['start']->copy(),
                );

                $granted++;
            }
        }

        return $granted;
    }

    /**
     * Credit one month's accrual for every `monthly_accrual` type, once per month (§6.2).
     *
     * Keyed on `occurred_on`, so re-running the command on the 2nd after it failed on the 1st changes
     * nothing.
     */
    public function accrueMonth(int $year, int $month, ?Employee $employee = null): int
    {
        $on = Carbon::create($year, $month, 1)->startOfDay();
        $leaveYear = $this->leaveYearFor($on);

        $types = LeaveType::query()->where('is_active', true)->get()
            ->filter(fn (LeaveType $type): bool => $type->accrual_method === LeaveAccrualMethod::MonthlyAccrual);

        $accrued = 0;

        foreach ($this->employeesFor($employee, $on->copy()->endOfMonth()) as $person) {
            foreach ($types as $type) {
                if (! $type->appliesTo($person->employment_type)) {
                    continue;
                }

                $days = Money::round((string) $type->accrual_days_per_month, 4);

                if (Money::isZero($days)) {
                    continue;
                }

                $already = LeaveBalanceTransaction::query()
                    ->where('employee_id', $person->getKey())
                    ->where('leave_type_id', $type->getKey())
                    ->where('reason', LeaveLedgerReason::MonthlyAccrual)
                    ->whereDate('occurred_on', $on->toDateString())
                    ->exists();

                if ($already) {
                    continue;
                }

                $this->post(
                    employee: $person,
                    type: $type,
                    leaveYear: $leaveYear['year'],
                    entryType: LedgerEntryType::Credit,
                    reason: LeaveLedgerReason::MonthlyAccrual,
                    days: $days,
                    occurredOn: $on->copy(),
                );

                $accrued++;
            }
        }

        return $accrued;
    }

    /**
     * Carry what the type allows into the next year and lapse the rest (§6.2).
     *
     * Both halves are written: the carry as `carry_forward_in` on the new year, the remainder as
     * `year_end_lapse` on the old one. Lapsing silently would leave an employee looking at a balance that
     * vanished with no row to point at.
     *
     * **The closed year keeps what it carried.** Only the lapsed part is debited from the old year, so its
     * row reads "ended 2026 with 5 days, 6.5 expired" rather than claiming the carried days were lost.
     * Nothing sums `available_days` across years — every caller resolves one leave year from a date — so
     * the five days are counted once, in the year they can actually be spent.
     */
    public function carryForward(int $fromYear, int $toYear, ?Employee $employee = null): int
    {
        $from = $this->leaveYearWindow($fromYear);
        $to = $this->leaveYearWindow($toYear);
        $carried = 0;

        $balances = LeaveBalance::query()
            ->with(['employee', 'leaveType'])
            ->where('leave_year', $fromYear)
            ->when($employee !== null, fn ($query) => $query->where('employee_id', $employee->getKey()))
            ->get();

        foreach ($balances as $balance) {
            $type = $balance->leaveType;
            $person = $balance->employee;

            if ($type === null || $person === null) {
                continue;
            }

            $remaining = Money::round((string) $balance->available_days, 4);

            if (Money::lessThan($remaining, '0.0001')) {
                continue;
            }

            $allowed = $type->carry_forward_enabled && (bool) setting('hr.leave_carry_forward_enabled', true)
                ? Money::min($remaining, Money::round((string) $type->max_carry_forward_days, 4))
                : '0.0000';

            $lapse = Money::round(Money::sub($remaining, $allowed), 4);

            if (Money::isPositive($allowed) && ! $this->hasEntry($person, $type, $toYear, LeaveLedgerReason::CarryForwardIn)) {
                $this->post(
                    employee: $person,
                    type: $type,
                    leaveYear: $toYear,
                    entryType: LedgerEntryType::Credit,
                    reason: LeaveLedgerReason::CarryForwardIn,
                    days: $allowed,
                    occurredOn: $to['start']->copy(),
                    notes: sprintf('Carried forward from %d.', $fromYear),
                );
                $carried++;
            }

            if (Money::isPositive($lapse) && ! $this->hasEntry($person, $type, $fromYear, LeaveLedgerReason::YearEndLapse)) {
                $this->post(
                    employee: $person,
                    type: $type,
                    leaveYear: $fromYear,
                    entryType: LedgerEntryType::Debit,
                    reason: LeaveLedgerReason::YearEndLapse,
                    days: $lapse,
                    occurredOn: $from['end']->copy(),
                    notes: sprintf('Lapsed at the end of %d.', $fromYear),
                );
            }
        }

        return $carried;
    }

    /*
    |--------------------------------------------------------------------------
    | The request lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * Hold the days a pending request would spend (§6.5.4).
     *
     * Under the balance row's lock, so two applications filed from two tabs cannot each see the last day
     * as available. The refusal names the exact shortfall (HR-9) — "you have 1.5 days and asked for 3" is
     * actionable; "insufficient balance" is not.
     */
    public function reserve(LeaveRequest $request): void
    {
        $type = $request->leaveType;
        $employee = $request->employee;
        $leaveYear = $this->leaveYearFor($request->from_date->copy());

        DB::transaction(function () use ($request, $type, $employee, $leaveYear): void {
            $balance = $this->balanceFor($employee, $type, $leaveYear['year'], lock: true);
            $available = Money::round((string) $balance->available_days, 4);
            $wanted = Money::round((string) $request->total_days, 4);

            if (Money::lessThan($available, $wanted) && ! $type->allowsNegative()) {
                throw HrRuleException::refuse('total_days', sprintf(
                    'That is %s day(s) of %s and only %s are available. Either take the days as unpaid, or '
                    .'ask HR to allow a negative balance for this type.',
                    rtrim(rtrim($wanted, '0'), '.'),
                    $type->name,
                    rtrim(rtrim($available, '0'), '.'),
                ));
            }

            $this->post(
                employee: $employee,
                type: $type,
                leaveYear: $leaveYear['year'],
                entryType: LedgerEntryType::Debit,
                reason: LeaveLedgerReason::Reservation,
                days: $wanted,
                occurredOn: $request->from_date->copy(),
                request: $request,
                balance: $balance,
            );

            $request->forceFill(['balance_snapshot_days' => $available])->save();
        });
    }

    /**
     * Turn a reservation into a consumption (§6.5.4).
     *
     * Both rows in one transaction: releasing first and consuming second means there is never an instant
     * in which the days are free for somebody else's application to take.
     */
    public function consume(LeaveRequest $request): void
    {
        $leaveYear = $this->leaveYearFor($request->from_date->copy());

        DB::transaction(function () use ($request, $leaveYear): void {
            $balance = $this->balanceFor($request->employee, $request->leaveType, $leaveYear['year'], lock: true);
            $days = Money::round((string) $request->total_days, 4);

            $this->post(
                employee: $request->employee,
                type: $request->leaveType,
                leaveYear: $leaveYear['year'],
                entryType: LedgerEntryType::Credit,
                reason: LeaveLedgerReason::ReservationRelease,
                days: $days,
                occurredOn: $request->from_date->copy(),
                request: $request,
                balance: $balance,
            );

            $this->post(
                employee: $request->employee,
                type: $request->leaveType,
                leaveYear: $leaveYear['year'],
                entryType: LedgerEntryType::Debit,
                reason: LeaveLedgerReason::LeaveConsumed,
                days: $days,
                occurredOn: $request->from_date->copy(),
                request: $request,
                balance: $balance,
            );
        });
    }

    /**
     * Give the days back — a rejection, a cancellation before approval, or a cancellation after it.
     *
     * The reason decides which column moves, which is why the caller passes it rather than the service
     * guessing from the request's status.
     */
    public function release(LeaveRequest $request, LeaveLedgerReason $reason): void
    {
        $leaveYear = $this->leaveYearFor($request->from_date->copy());

        DB::transaction(function () use ($request, $reason, $leaveYear): void {
            $balance = $this->balanceFor($request->employee, $request->leaveType, $leaveYear['year'], lock: true);

            $this->post(
                employee: $request->employee,
                type: $request->leaveType,
                leaveYear: $leaveYear['year'],
                entryType: LedgerEntryType::Credit,
                reason: $reason,
                days: Money::round((string) $request->total_days, 4),
                occurredOn: $request->from_date->copy(),
                request: $request,
                balance: $balance,
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Manual movement and the checks
    |--------------------------------------------------------------------------
    */

    /**
     * A human moves somebody's balance (§6.2). The note is mandatory: a balance that changed without an
     * explanation is the one thing an employee will always ask about.
     */
    public function adjust(
        Employee $employee,
        LeaveType $type,
        string $signedDays,
        string $note,
        ?User $actor = null,
        ?Carbon $on = null,
    ): LeaveBalanceTransaction {
        if (trim($note) === '') {
            throw HrRuleException::reasonRequired(
                'notes',
                'Say why the balance is being changed — this row is the only explanation the employee '
                .'will ever see.'
            );
        }

        $signedDays = Money::round($signedDays, 4);

        if (Money::isZero($signedDays)) {
            throw HrRuleException::refuse('days', 'An adjustment of zero days changes nothing.');
        }

        $on ??= now()->startOfDay();
        $leaveYear = $this->leaveYearFor($on);

        return DB::transaction(fn (): LeaveBalanceTransaction => $this->post(
            employee: $employee,
            type: $type,
            leaveYear: $leaveYear['year'],
            entryType: Money::isNegative($signedDays) ? LedgerEntryType::Debit : LedgerEntryType::Credit,
            reason: LeaveLedgerReason::ManualAdjustment,
            days: Money::abs($signedDays),
            occurredOn: $on->copy(),
            notes: trim($note),
            actor: $actor,
        ));
    }

    /**
     * How many days are available, from the ledger when `fresh`, from the cache otherwise.
     */
    public function available(Employee $employee, LeaveType $type, ?Carbon $on = null, bool $fresh = false): string
    {
        $leaveYear = $this->leaveYearFor($on ?? now());

        if ($fresh) {
            return $this->ledgerTotal($employee, $type, $leaveYear['year']);
        }

        $cached = LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('leave_year', $leaveYear['year'])
            ->value('available_days');

        return Money::round((string) ($cached ?? '0'), 4);
    }

    /**
     * Throw when a cached balance disagrees with its ledger (HR-7).
     *
     * The tests call this after every scenario. A cache that can drift is a cache that will, and leave
     * days are the kind of number people plan holidays around.
     */
    public function assertConsistent(Employee $employee, ?LeaveType $type = null): void
    {
        $balances = LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->when($type !== null, fn ($query) => $query->where('leave_type_id', $type->getKey()))
            ->get();

        foreach ($balances as $balance) {
            $derived = $this->ledgerTotal($employee, $balance->leave_type_id, $balance->leave_year);
            $cached = Money::round((string) $balance->available_days, 4);

            if (! Money::equals($derived, $cached)) {
                throw new LogicException(sprintf(
                    'Leave balance #%s (employee %s, type %s, year %d) is cached as %s but the ledger sums '
                    .'to %s (phase-07 HR-7). The cache is only ever a fast answer; when the two disagree '
                    .'the ledger is right and the cache is the bug.',
                    (string) $balance->getKey(),
                    (string) $employee->getKey(),
                    (string) $balance->leave_type_id,
                    (int) $balance->leave_year,
                    $cached,
                    $derived,
                ));
            }
        }
    }

    /**
     * The balance row for a person, type and year — created at zero when it does not exist.
     */
    public function balanceFor(Employee $employee, LeaveType $type, int $leaveYear, bool $lock = false): LeaveBalance
    {
        $window = $this->leaveYearWindow($leaveYear);

        $query = LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('leave_year', $leaveYear);

        if ($lock) {
            $query->lockForUpdate();
        }

        $balance = $query->first();

        if ($balance !== null) {
            return $balance;
        }

        $balance = new LeaveBalance;
        $balance->forceFill([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'leave_year' => $leaveYear,
            'period_start' => $window['start']->toDateString(),
            'period_end' => $window['end']->toDateString(),
        ])->save();

        return $balance;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Write one ledger row and move the cache it justifies, in the same transaction (HR-7).
     *
     * This is the **only** method in the codebase that writes `leave_balances`. Every other path goes
     * through a reason, which is what keeps the cache derivable.
     */
    private function post(
        Employee $employee,
        LeaveType $type,
        int $leaveYear,
        LedgerEntryType $entryType,
        LeaveLedgerReason $reason,
        string $days,
        Carbon $occurredOn,
        ?LeaveRequest $request = null,
        ?string $notes = null,
        ?User $actor = null,
        ?LeaveBalance $balance = null,
    ): LeaveBalanceTransaction {
        $days = Money::round($days, 4);

        if (! Money::isPositive($days)) {
            throw HrRuleException::refuse('days', 'A ledger entry carries a positive number of days; the '
                .'direction is the entry type, never a negative magnitude.');
        }

        if ($reason->requiresNote() && ($notes === null || trim($notes) === '')) {
            throw HrRuleException::reasonRequired('notes', sprintf(
                'A %s entry needs a note saying why.',
                $reason->label()
            ));
        }

        return DB::transaction(function () use (
            $employee, $type, $leaveYear, $entryType, $reason, $days, $occurredOn, $request, $notes, $actor, $balance
        ): LeaveBalanceTransaction {
            $balance ??= $this->balanceFor($employee, $type, $leaveYear, lock: true);

            [$column, $additive] = self::COLUMNS[$reason->value];

            $signed = $entryType === LedgerEntryType::Credit ? $days : Money::negate($days);
            $delta = $additive ? $signed : Money::negate($signed);

            $balance->forceFill([
                $column => Money::round(Money::add((string) $balance->{$column}, $delta), 4),
                'last_recalculated_at' => now(),
            ]);

            $balance->forceFill([
                'available_days' => $this->derive($balance),
            ])->save();

            $entry = new LeaveBalanceTransaction;
            $entry->forceFill([
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
                'leave_year' => $leaveYear,
                'entry_type' => $entryType,
                'reason' => $reason,
                'days' => $days,
                'leave_request_id' => $request?->getKey(),
                'balance_after_days' => (string) $balance->available_days,
                'notes' => $notes,
                'performed_by' => $actor?->getKey(),
                'occurred_on' => $occurredOn->toDateString(),
            ])->save();

            return $entry;
        });
    }

    /**
     * `available_days` from the eight columns — the formula of §2.13, in one place.
     */
    private function derive(LeaveBalance $balance): string
    {
        $plus = Money::sum(
            (string) $balance->entitled_days,
            (string) $balance->carried_forward_days,
            (string) $balance->accrued_days,
            (string) $balance->adjusted_days,
        );

        $minus = Money::sum(
            (string) $balance->consumed_days,
            (string) $balance->pending_days,
            (string) $balance->encashed_days,
            (string) $balance->expired_days,
        );

        return Money::round(Money::sub($plus, $minus), 4);
    }

    private function ledgerTotal(Employee $employee, LeaveType|int $type, int $leaveYear): string
    {
        $rows = LeaveBalanceTransaction::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type instanceof LeaveType ? $type->getKey() : $type)
            ->where('leave_year', $leaveYear)
            ->pluck('signed_days');

        return Money::round(Money::sum($rows->map(fn ($value): string => (string) $value)->all()), 4);
    }

    private function hasEntry(Employee $employee, LeaveType $type, int $leaveYear, LeaveLedgerReason $reason): bool
    {
        return LeaveBalanceTransaction::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('leave_year', $leaveYear)
            ->where('reason', $reason)
            ->exists();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employeesFor(?Employee $employee, Carbon $by)
    {
        if ($employee !== null) {
            return collect([$employee]);
        }

        return Employee::query()
            ->whereDate('joining_date', '<=', $by->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('exit_date')
                ->orWhereDate('exit_date', '>=', $by->copy()->startOfMonth()->toDateString()))
            ->get()
            ->filter(fn (Employee $person): bool => $person->status->isPayrollEligible())
            ->values();
    }
}
