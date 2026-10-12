<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\DataObjects\Support\AudienceInput;
use App\Enums\AttendanceDeductionStatus;
use App\Enums\AttendanceDeductionType;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceDeduction;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRunItem;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Support\NotificationService;
use App\Support\Hr\PayrollPeriod;
use App\Support\Hr\PayslipLine;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Attendance-based salary deductions — the one place they are decided.
 *
 * **One idempotent operation does all of it: `syncMonth()`.** It reads an employee's attendance rows for a
 * month as `AttendanceService::resolve()` left them (shift timings, grace minutes, holidays, weekly offs,
 * approved leave and exempt staff are already decided there), works out which deductions *should* exist,
 * and moves the `attendance_deductions` rows to match:
 *
 *   should exist, no row         -> a pending row               (the employee is told)
 *   should exist, pending row    -> its figures updated if moved (told only if the amount moved)
 *   should exist, auto-reversed  -> pending again                (told)
 *   no longer should, pending    -> reversed, with the reason    (told)
 *   applied (a locked slip took it) -> never touched here: a refund is HR's decision, through a correction slip
 *
 * Running it twice changes nothing the second time, so it is safe on every attendance write, on every
 * recalculation and import, when a rule is switched on or off, and again inside payroll generation. Two
 * runs for the same employee are serialised on the employee row, and `uq_attd_day` stops a duplicate even
 * if they were not.
 *
 * **The daily salary is payroll's own per-day amount** (phase-07 §6.6 step 4): the gross or basic of the
 * structure in force at the period end, per `hr.lop_basis`, divided by calendar days, working days or 30,
 * per `hr.payroll_day_basis`. Inside generation the run's snapshot is used, so the slip line and the rows
 * can never disagree.
 */
class AttendanceDeductionService
{
    public const EVENT_RECORDED = 'payroll.attendance_deduction_recorded';

    public const EVENT_ADJUSTED = 'payroll.attendance_deduction_adjusted';

    public const EVENT_APPLIED = 'payroll.attendance_deduction_applied';

    public const EVENT_REVERSED = 'payroll.attendance_deduction_reversed';

    public function __construct(
        private readonly SalaryStructureService $structures,
        private readonly WorkCalendarService $calendar,
        private readonly NotificationService $notifications,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    /**
     * The settings the rules read — live, or from a payroll run's snapshot.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array{late_enabled: bool, late_percent: string, absence_enabled: bool, allowance: int, day_basis: string, lop_basis: string}
     */
    public function settings(?array $snapshot = null): array
    {
        $read = static fn (string $key, mixed $default): mixed => $snapshot !== null
            ? ($snapshot[$key] ?? $default)
            : setting('hr.'.$key, $default);

        $percent = Money::round((string) $read('late_arrival_deduction_percent', '25.0000'), 4);

        return [
            'late_enabled' => (bool) $read('late_arrival_deduction_enabled', false),
            'late_percent' => Money::isPositive($percent) ? $percent : '25.0000',
            'absence_enabled' => (bool) $read('excess_absence_deduction_enabled', false),
            'allowance' => max(0, (int) $read('monthly_absence_allowance_days', 1)),
            'day_basis' => (string) ($snapshot['day_basis'] ?? setting('hr.payroll_day_basis', 'calendar_days')),
            'lop_basis' => (string) ($snapshot['lop_basis'] ?? setting('hr.lop_basis', 'gross')),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The sync
    |--------------------------------------------------------------------------
    */

    /**
     * Bring one employee's month into line with their attendance. Safe to call any number of times.
     *
     * @param  array<string, mixed>|null  $snapshot  a payroll run's settings snapshot; null reads live settings
     * @return array{created: int, updated: int, reversed: int, skipped: string|null}
     */
    public function syncMonth(Employee $employee, int $year, int $month, ?array $snapshot = null): array
    {
        $report = ['created' => 0, 'updated' => 0, 'reversed' => 0, 'skipped' => null];

        $period = PayrollPeriod::of($year, $month);

        // A period payroll has locked is history: its attendance can no longer change, and neither may this.
        if ($this->periodIsLocked($employee, $year, $month)) {
            $report['skipped'] = 'period_locked';

            return $report;
        }

        $settings = $this->settings($snapshot);
        $toNotify = [];

        DB::transaction(function () use ($employee, $year, $month, $period, $settings, &$report, &$toNotify): void {
            // Serialise every sync for this employee: two attendance writes in parallel must not both
            // decide "no row yet" and race to insert.
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $existing = AttendanceDeduction::query()
                ->where('employee_id', $employee->getKey())
                ->forPeriod($year, $month)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (AttendanceDeduction $row): string => $this->key($row->deduction_type, $row->deduction_date));

            $rows = $this->attendanceRows($employee, $period);
            $daily = null;
            $desired = [];

            if (($settings['late_enabled'] || $settings['absence_enabled']) && ! $employee->is_attendance_exempt) {
                $daily = $this->dailySalary($employee, $period, $settings);

                if ($daily === null) {
                    // No salary structure means payroll skips this employee too — record nothing rather than
                    // a deduction against a salary that does not exist. Existing rows are left as they are.
                    $report['skipped'] = 'no_salary_structure';

                    return;
                }

                $desired = $this->desired($rows, $settings, $daily);
            }

            foreach ($desired as $key => $wanted) {
                $row = $existing->get($key);

                if ($row === null) {
                    $toNotify[] = [$this->create($employee, $wanted), self::EVENT_RECORDED];
                    $report['created']++;

                    continue;
                }

                if ($row->isApplied()) {
                    continue;
                }

                if ($row->status === AttendanceDeductionStatus::Reversed) {
                    // HR waived it by hand: that decision stands until HR says otherwise.
                    if ($row->reversed_by !== null) {
                        continue;
                    }

                    $this->fill($row, $wanted);
                    $row->forceFill([
                        'status' => AttendanceDeductionStatus::Pending,
                        'reversed_at' => null,
                        'reversal_reason' => null,
                    ])->save();

                    $toNotify[] = [$row, self::EVENT_RECORDED];
                    $report['created']++;

                    continue;
                }

                $before = (string) $row->amount;
                $this->fill($row, $wanted);

                if ($row->isDirty()) {
                    $row->save();
                    $report['updated']++;

                    if (Money::compare($before, (string) $row->amount) !== 0) {
                        $toNotify[] = [$row, self::EVENT_ADJUSTED];
                    }
                }
            }

            foreach ($existing as $key => $row) {
                if (isset($desired[$key]) || ! $row->isPending()) {
                    continue;
                }

                $row->forceFill([
                    'status' => AttendanceDeductionStatus::Reversed,
                    'reversed_at' => now(),
                    'reversed_by' => null,
                    'reversal_reason' => $this->autoReversalReason($row, $rows, $settings),
                ])->save();

                $toNotify[] = [$row, self::EVENT_REVERSED];
                $report['reversed']++;
            }
        });

        foreach ($toNotify as [$row, $event]) {
            $this->notify($row, $event);
        }

        return $report;
    }

    /**
     * Every employee who has attendance in the month — after a rule was switched on or off.
     */
    public function syncPeriod(int $year, int $month): int
    {
        $period = PayrollPeriod::of($year, $month);
        $synced = 0;

        Employee::query()
            ->whereDate('joining_date', '<=', $period->end->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('exit_date')
                ->orWhereDate('exit_date', '>=', $period->start->toDateString()))
            ->chunkById(100, function ($employees) use ($year, $month, &$synced): void {
                foreach ($employees as $employee) {
                    $this->syncSafely($employee, $year, $month);
                    $synced++;
                }
            });

        return $synced;
    }

    /**
     * Every employee-month holding a pending row or one the sync itself reversed, outside the periods
     * already synced. A rule switched off must reverse a pending deduction wherever it sits — a late mark
     * entered ahead of the month, a month left open — and switched back on must bring it back, not only in
     * the months a settings save re-reads in full. Rows HR reversed by hand are not a reason to re-read; a
     * locked period is refused by `syncMonth()`.
     *
     * @param  list<array{0: int, 1: int}>  $except  periods already synced, as [year, month]
     */
    public function syncRecordedOutside(array $except): int
    {
        $skip = array_map(static fn (array $period): string => $period[0].'-'.$period[1], $except);
        $synced = 0;

        $pairs = AttendanceDeduction::query()
            ->where(fn ($query) => $query
                ->where('status', AttendanceDeductionStatus::Pending->value)
                ->orWhere(fn ($auto) => $auto
                    ->where('status', AttendanceDeductionStatus::Reversed->value)
                    ->whereNull('reversed_by')))
            ->toBase()
            ->select(['employee_id', 'period_year', 'period_month'])
            ->distinct()
            ->orderBy('employee_id')
            ->get();

        foreach ($pairs as $pair) {
            if (in_array($pair->period_year.'-'.$pair->period_month, $skip, true)) {
                continue;
            }

            $employee = Employee::query()->find($pair->employee_id);

            if ($employee !== null) {
                $this->syncSafely($employee, (int) $pair->period_year, (int) $pair->period_month);
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * What the attendance hook calls on every write: nothing at all while both rules are off and the month
     * has no pending deduction to reverse — so a business that never turns the rules on pays no cost.
     */
    public function syncIfNeeded(Employee $employee, int $year, int $month): void
    {
        $settings = $this->settings();

        if (! $settings['late_enabled'] && ! $settings['absence_enabled']) {
            $hasPending = AttendanceDeduction::query()
                ->where('employee_id', $employee->getKey())
                ->forPeriod($year, $month)
                ->pending()
                ->exists();

            if (! $hasPending) {
                return;
            }
        }

        $this->syncSafely($employee, $year, $month);
    }

    /**
     * The sync, for callers that must never fail because of it (an attendance write, a settings save).
     * A failure is reported and left for the next sync — payroll generation always runs one.
     */
    public function syncSafely(Employee $employee, int $year, int $month): void
    {
        try {
            $this->syncMonth($employee, $year, $month);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | What payroll asks
    |--------------------------------------------------------------------------
    */

    /**
     * The pending rows a slip for this period should take — this month's, plus any earlier month's that a
     * locked run never took (a correction carried to the next payroll rather than lost).
     *
     * @return list<AttendanceDeduction>
     */
    public function pendingFor(Employee $employee, PayrollPeriod $period): array
    {
        return AttendanceDeduction::query()
            ->where('employee_id', $employee->getKey())
            ->pending()
            ->where(fn ($query) => $query
                ->where('period_year', '<', $period->year)
                ->orWhere(fn ($same) => $same
                    ->where('period_year', $period->year)
                    ->where('period_month', '<=', $period->month)))
            ->orderBy('deduction_date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The loss-of-pay days that are chargeable absences — what the excess-absence rule charges instead of
     * the unpaid-leave line. Read from the same rows the rule reads, so the two always agree.
     */
    public function absenceLopDays(Employee $employee, PayrollPeriod $period): string
    {
        $days = Money::round('0', 4);

        foreach ($this->attendanceRows($employee, $period) as $row) {
            if ($this->isChargeableAbsence($row)) {
                $days = Money::add($days, Money::sub('1', Money::round((string) $row->payable_factor, 4)));
            }
        }

        return Money::round($days, 4);
    }

    /**
     * A locked slip took these rows: they are applied, and the employee is told. Called inside the lock's
     * transaction, so a failed lock leaves them pending.
     *
     * @param  list<int>  $ids
     */
    public function markApplied(array $ids, PayrollRunItem $item): int
    {
        if ($ids === []) {
            return 0;
        }

        $applied = 0;

        AttendanceDeduction::query()
            ->whereIn('id', $ids)
            ->where('employee_id', $item->employee_id)
            ->pending()
            ->lockForUpdate()
            ->get()
            ->each(function (AttendanceDeduction $row) use ($item, &$applied): void {
                $row->forceFill([
                    'status' => AttendanceDeductionStatus::Applied,
                    'payroll_run_id' => $item->payroll_run_id,
                    'payroll_run_item_id' => $item->getKey(),
                    'applied_at' => now(),
                ])->save();

                $applied++;

                DB::afterCommit(fn () => $this->notify($row, self::EVENT_APPLIED));
            });

        return $applied;
    }

    /*
    |--------------------------------------------------------------------------
    | HR's decision
    |--------------------------------------------------------------------------
    */

    /**
     * Reverse one deduction by hand.
     *
     * A pending row is simply reversed and stays reversed (a later sync respects the decision). An applied
     * row was paid through a locked slip, so it is refunded the way every locked slip is corrected — a
     * correction slip with an earning line for the amount (phase-07 §6.8) — and the row points at it.
     */
    public function reverse(AttendanceDeduction $deduction, string $reason, User $actor): AttendanceDeduction
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw HrRuleException::reasonRequired('reason', 'Say why the deduction is being reversed (at least 5 characters).');
        }

        if ($deduction->status === AttendanceDeductionStatus::Reversed) {
            throw HrRuleException::refuse('status', 'This deduction has already been reversed.');
        }

        $reversed = DB::transaction(function () use ($deduction, $reason, $actor): AttendanceDeduction {
            /** @var AttendanceDeduction $row */
            $row = AttendanceDeduction::query()->whereKey($deduction->getKey())->lockForUpdate()->firstOrFail();

            if ($row->status === AttendanceDeductionStatus::Reversed) {
                throw HrRuleException::refuse('status', 'This deduction has already been reversed.');
            }

            $correctionId = null;

            if ($row->isApplied()) {
                $original = PayrollRunItem::query()->find($row->payroll_run_item_id);

                if ($original === null) {
                    throw HrRuleException::refuse('status', 'The salary slip that took this deduction cannot be found, so it cannot be refunded.');
                }

                $correction = app(PayrollRunService::class)->issueCorrection(
                    $original,
                    [new PayslipLine(
                        componentCode: 'ATT_REFUND',
                        componentName: sprintf('Refund: %s deduction of %s', strtolower($row->deduction_type->label()), $row->deduction_date->format('j M Y')),
                        group: SalaryComponentGroup::OtherEarning,
                        side: SalaryComponentType::Earning,
                        calculation: SalaryComponentCalculation::Fixed,
                        amount: Money::round((string) $row->amount),
                        isTaxable: false,
                        sourceType: 'attendance_deductions',
                        sourceId: (int) $row->getKey(),
                        calculationNote: $reason,
                    )],
                    sprintf('Attendance deduction reversed: %s', $reason),
                    $actor,
                );

                $correctionId = (int) $correction->getKey();
            }

            $row->withReason($reason)->forceFill([
                'status' => AttendanceDeductionStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'reversal_reason' => mb_substr($reason, 0, 255),
                'correction_item_id' => $correctionId,
            ])->save();

            return $row;
        });

        $this->notify($reversed, self::EVENT_REVERSED);

        return $reversed;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The deductions the month's attendance calls for, keyed by type and date.
     *
     * @param  Collection<int, Attendance>  $rows
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $daily
     * @return array<string, array<string, mixed>>
     */
    private function desired(Collection $rows, array $settings, array $daily): array
    {
        $desired = [];

        if ($settings['late_enabled']) {
            foreach ($rows as $row) {
                if ($row->status !== AttendanceStatus::Late || $row->day_type !== DayType::Working) {
                    continue;
                }

                $amount = Money::round(Money::percentage($daily['daily'], $settings['late_percent']));

                $desired[$this->key(AttendanceDeductionType::LateArrival, $row->attendance_date)] = [
                    'type' => AttendanceDeductionType::LateArrival,
                    'attendance' => $row,
                    'rate' => $settings['late_percent'],
                    'amount' => $amount,
                    'sequence' => null,
                    'allowance' => null,
                    'reason' => sprintf('Late arrival on %s (%d min after the shift start and grace).', $row->attendance_date->format('j M Y'), (int) $row->late_minutes),
                    'daily' => $daily,
                ];
            }
        }

        if ($settings['absence_enabled']) {
            $sequence = 0;

            foreach ($rows as $row) {
                if (! $this->isChargeableAbsence($row)) {
                    continue;
                }

                $sequence++;

                if ($sequence <= $settings['allowance']) {
                    continue;
                }

                $desired[$this->key(AttendanceDeductionType::ExcessAbsence, $row->attendance_date)] = [
                    'type' => AttendanceDeductionType::ExcessAbsence,
                    'attendance' => $row,
                    'rate' => '100.0000',
                    'amount' => Money::round($daily['daily']),
                    'sequence' => $sequence,
                    'allowance' => $settings['allowance'],
                    'reason' => sprintf('Absence %d of the month on %s; %d allowed without a deduction.', $sequence, $row->attendance_date->format('j M Y'), $settings['allowance']),
                    'daily' => $daily,
                ];
            }
        }

        return $desired;
    }

    /**
     * A chargeable absence: a working day the employee was absent without approved leave. Weekly offs,
     * holidays and leave never resolve to `absent` (AttendanceService §6.3 steps 3-6), and the day type is
     * checked again here so a hand-entered row on a holiday is never charged either.
     */
    private function isChargeableAbsence(Attendance $row): bool
    {
        return $row->status === AttendanceStatus::Absent
            && $row->day_type === DayType::Working
            && $row->leave_request_id === null;
    }

    /**
     * One row per date, in date order. If a date somehow has more than one row, the latest wins — a date
     * can only ever be charged once.
     *
     * @return Collection<int, Attendance>
     */
    private function attendanceRows(Employee $employee, PayrollPeriod $period): Collection
    {
        return Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('attendance_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Attendance $row): string => $row->attendance_date->toDateString())
            ->values();
    }

    /**
     * Payroll's per-day amount for this employee and month (phase-07 §6.6 step 4), or null when there is
     * no salary structure.
     *
     * @param  array<string, mixed>  $settings
     * @return array{daily: string, divisor: string, day_basis: string, lop_basis: string, prorate_base: string, structure_id: int}|null
     */
    public function dailySalary(Employee $employee, PayrollPeriod $period, array $settings): ?array
    {
        $structure = $this->structures->effectiveOn($employee, $period->end);

        if ($structure === null) {
            return null;
        }

        $prorateBase = $settings['lop_basis'] === 'basic'
            ? Money::round((string) $structure->basic_salary)
            : Money::round((string) $structure->gross_salary);

        $divisor = match ($settings['day_basis']) {
            'working_days' => $this->workingDays($employee, $period),
            'fixed_30' => '30.0000',
            default => Money::round((string) $period->calendarDays(), 4),
        };

        if (! Money::isPositive($divisor)) {
            return null;
        }

        return [
            'daily' => Money::round(Money::div($prorateBase, $divisor)),
            'divisor' => $divisor,
            'day_basis' => $settings['day_basis'],
            'lop_basis' => $settings['lop_basis'],
            'prorate_base' => $prorateBase,
            'structure_id' => (int) $structure->getKey(),
        ];
    }

    /**
     * The working-days divisor: the summary's figure when the month has one (exactly what payroll divides
     * by), otherwise the working days of the month inside the employment dates, from the calendar.
     */
    private function workingDays(Employee $employee, PayrollPeriod $period): string
    {
        $summary = AttendanceMonthlySummary::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->value('working_days');

        if ($summary !== null && Money::isPositive((string) $summary)) {
            return Money::round((string) $summary, 4);
        }

        $from = $employee->joining_date->greaterThan($period->start) ? $employee->joining_date->copy() : $period->start->copy();
        $to = $employee->exit_date !== null && $employee->exit_date->lessThan($period->end) ? $employee->exit_date->copy() : $period->end->copy();

        $count = $this->calendar->datesIn(Carbon::parse($from), Carbon::parse($to))
            ->filter(fn (Carbon $date): bool => $this->calendar->dayTypeFor($employee, $date)->countsTowardWorkingDays())
            ->count();

        return Money::round((string) $count, 4);
    }

    private function periodIsLocked(Employee $employee, int $year, int $month): bool
    {
        return AttendanceMonthlySummary::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNotNull('locked_at')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $wanted
     */
    private function create(Employee $employee, array $wanted): AttendanceDeduction
    {
        $row = new AttendanceDeduction;
        $row->forceFill([
            'employee_id' => $employee->getKey(),
            'branch_id' => $employee->branch_id,
            'deduction_type' => $wanted['type'],
            'deduction_date' => $wanted['attendance']->attendance_date->toDateString(),
            'period_year' => (int) $wanted['attendance']->attendance_date->year,
            'period_month' => (int) $wanted['attendance']->attendance_date->month,
            'currency' => (string) setting('localization.currency', 'PKR'),
            'status' => AttendanceDeductionStatus::Pending,
        ]);

        $this->fill($row, $wanted);
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $wanted
     */
    private function fill(AttendanceDeduction $row, array $wanted): void
    {
        $daily = $wanted['daily'];

        $row->forceFill([
            'attendance_id' => $wanted['attendance']->getKey(),
            'daily_salary' => $daily['daily'],
            'rate_percentage' => $wanted['rate'],
            'amount' => $wanted['amount'],
            'absence_sequence' => $wanted['sequence'],
            'allowance_days' => $wanted['allowance'],
            'reason' => mb_substr($wanted['reason'], 0, 255),
            'calculation' => [
                'day_basis' => $daily['day_basis'],
                'lop_basis' => $daily['lop_basis'],
                'prorate_base' => $daily['prorate_base'],
                'divisor' => $daily['divisor'],
                'daily_salary' => $daily['daily'],
                'rate_percentage' => $wanted['rate'],
                'amount' => $wanted['amount'],
                'salary_structure_id' => $daily['structure_id'],
                'attendance_status' => $wanted['attendance']->status->value,
                'late_minutes' => (int) $wanted['attendance']->late_minutes,
            ],
        ]);

        // A JSON column compares as a string; only treat it as changed when a figure moved.
        if ($row->exists && ! $row->isDirty(['attendance_id', 'daily_salary', 'rate_percentage', 'amount', 'absence_sequence', 'allowance_days', 'reason'])) {
            $row->syncOriginalAttribute('calculation');
        }
    }

    /**
     * @param  Collection<int, Attendance>  $rows
     * @param  array<string, mixed>  $settings
     */
    private function autoReversalReason(AttendanceDeduction $row, Collection $rows, array $settings): string
    {
        $attendance = $rows->first(fn (Attendance $candidate): bool => $candidate->attendance_date->isSameDay($row->deduction_date));
        $date = $row->deduction_date->format('j M Y');

        if ($row->deduction_type === AttendanceDeductionType::LateArrival) {
            if (! $settings['late_enabled']) {
                return 'Late arrival deductions were switched off.';
            }

            return $attendance === null
                ? sprintf('The attendance record for %s no longer exists.', $date)
                : sprintf('Attendance for %s was corrected and is no longer a late arrival (now %s).', $date, strtolower($attendance->status->label()));
        }

        if (! $settings['absence_enabled']) {
            return 'Excess absence deductions were switched off.';
        }

        if ($attendance !== null && $attendance->status === AttendanceStatus::OnLeave) {
            return sprintf('%s is now approved leave.', $date);
        }

        if ($attendance !== null && $this->isChargeableAbsence($attendance)) {
            return sprintf('%s is now within the monthly allowance of %d day(s).', $date, $settings['allowance']);
        }

        return $attendance === null
            ? sprintf('The attendance record for %s no longer exists.', $date)
            : sprintf('Attendance for %s was corrected and is no longer an absence (now %s).', $date, strtolower($attendance->status->label()));
    }

    private function key(AttendanceDeductionType $type, Carbon|\DateTimeInterface $date): string
    {
        return $type->value.'|'.Carbon::parse($date)->toDateString();
    }

    /**
     * Tell the employee — once per state. The row remembers the last state it was told about, so a sync
     * that changes nothing, a retried job or a second click never sends the same notice twice.
     */
    private function notify(AttendanceDeduction $row, string $event): void
    {
        $state = $event.'|'.$row->status->value.'|'.Money::round((string) $row->amount);

        if ($row->notified_state === $state) {
            return;
        }

        $employee = $row->relationLoaded('employee') ? $row->employee : Employee::query()->find($row->employee_id);
        $user = $employee?->user_id === null ? null : User::query()->find($employee->user_id);

        if ($user !== null) {
            try {
                $currency = (string) ($row->currency ?? setting('localization.currency', 'PKR'));

                $this->notifications->dispatch($event, AudienceInput::of($user), [
                    'title' => $this->title($row, $event),
                    'body' => $row->explanation(),
                    'url' => route('admin.my.deductions.index'),
                    'deduction_id' => (int) $row->getKey(),
                    'deduction_type' => $row->deduction_type->value,
                    'deduction_date' => $row->deduction_date->toDateString(),
                    'daily_salary' => Money::round((string) $row->daily_salary),
                    'rate_percentage' => Money::round((string) $row->rate_percentage, 4),
                    'absence_sequence' => $row->absence_sequence,
                    'amount' => Money::round((string) $row->amount),
                    'currency' => $currency,
                    'status' => $row->status->value,
                ]);
            } catch (Throwable $exception) {
                // The deduction stands whether or not the bell rang; the employee's list always shows it.
                Log::warning('Attendance deduction notification failed.', [
                    'deduction_id' => $row->getKey(),
                    'event' => $event,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }
        }

        $row->forceFill(['notified_state' => mb_substr($state, 0, 64), 'notified_at' => now()])->saveQuietly();
    }

    private function title(AttendanceDeduction $row, string $event): string
    {
        $what = $row->deduction_type === AttendanceDeductionType::LateArrival ? 'Late arrival deduction' : 'Excess leave deduction';

        return match ($event) {
            self::EVENT_ADJUSTED => $what.' adjusted',
            self::EVENT_APPLIED => $what.' applied to your salary',
            self::EVENT_REVERSED => $what.' reversed',
            default => $what,
        };
    }
}
