<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The monthly figures, and the **only** definition of each (phase-07 §6.2, §6.4).
 *
 * Payroll reads this row and nothing else. That is the whole point of the table: if two screens each
 * summed `attendances` their own way, a slip and a report would eventually disagree, and the disagreement
 * would be discovered by an employee rather than by us.
 *
 * The two rules that decide somebody's pay (§6.4):
 *
 * - **R1** `payable_days = SUM(payable_factor)` over **every** row in the period — working days, weekly
 *   offs and paid holidays all contribute — so a full month of attendance equals the calendar days.
 * - **R2** `lop_days = SUM(1 - payable_factor)` over rows whose `day_type = working` **only**. A weekend
 *   is never a loss of pay, and this is the line that guarantees it.
 *
 * **A mid-month joiner is not punished** (§6.10 #1): rows exist only from `joining_date`, so the days
 * before it are outside every sum. `calendar_days` still holds the whole month, because it is the payroll
 * divisor and dividing a monthly salary by a partial month would inflate the per-day amount.
 *
 * Rebuilding is idempotent and refused once payroll has locked the period (HR-18) — otherwise the
 * arithmetic behind a paid slip could be changed after the fact, quietly.
 */
class AttendanceSummaryService
{
    public function __construct(
        private readonly WorkCalendarService $calendar,
    ) {}

    /**
     * Build or rebuild one employee's summary for one month.
     */
    public function build(Employee $employee, int $year, int $month): AttendanceMonthlySummary
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($employee, $year, $month, $start, $end) {
            $summary = AttendanceMonthlySummary::query()
                ->where('employee_id', $employee->getKey())
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->first();

            if ($summary !== null && $summary->locked_at !== null) {
                throw LockedAttendanceException::forPeriod(
                    sprintf('The attendance summary for %s', $start->format('F Y')),
                    $summary->lockedByRun?->run_number ?? '(unknown run)'
                );
            }

            $rows = Attendance::query()
                ->where('employee_id', $employee->getKey())
                ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('attendance_date')
                ->get();

            $figures = $this->fold($rows);

            $summary ??= new AttendanceMonthlySummary;
            $summary->forceFill($figures + [
                'employee_id' => $employee->getKey(),
                'branch_id' => $employee->branch_id,
                'period_year' => $year,
                'period_month' => $month,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'calendar_days' => $start->daysInMonth,
                'generated_at' => now(),
            ])->save();

            return $summary;
        });
    }

    /**
     * Build the whole period, for a branch or for everybody. Returns how many summaries were written.
     *
     * Employees who had not joined by the end of the period, or who left before it started, are skipped:
     * a summary of zero days would look like a month of absence rather than like no employment.
     */
    public function buildPeriod(int $year, int $month, ?int $branchId = null): int
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        $this->calendar->preload($start, $end);

        $built = 0;

        Employee::query()
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('joining_date', '<=', $end->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('exit_date')
                ->orWhereDate('exit_date', '>=', $start->toDateString()))
            ->chunkById(100, function ($employees) use ($year, $month, &$built): void {
                foreach ($employees as $employee) {
                    $this->build($employee, $year, $month);
                    $built++;
                }
            });

        return $built;
    }

    /**
     * Mark a summary final — every date in the period has a resolved row, so nothing more is expected.
     *
     * Payroll does not require `is_final`; it is a signal to HR that the month is complete, and a
     * generation against an unfinished month is a decision somebody takes knowingly.
     */
    public function markFinal(Employee $employee, int $year, int $month): ?AttendanceMonthlySummary
    {
        $summary = AttendanceMonthlySummary::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        if ($summary === null) {
            return null;
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        $from = $employee->joining_date->greaterThan($start) ? $employee->joining_date->copy() : $start;
        $to = $employee->exit_date !== null && $employee->exit_date->lessThan($end)
            ? $employee->exit_date->copy()
            : $end;

        $expected = $this->calendar->datesIn($from, $to)->count();

        $have = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $summary->forceFill(['is_final' => $have >= $expected])->save();

        return $summary;
    }

    /**
     * Freeze the period's summaries behind a locked payroll run (HR-18, R5).
     */
    public function lockPeriod(int $year, int $month, int $payrollRunId, ?int $branchId = null): int
    {
        return AttendanceMonthlySummary::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNull('locked_at')
            ->update([
                'locked_at' => now(),
                'locked_by_payroll_run_id' => $payrollRunId,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The arithmetic — every figure defined once
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, Attendance>  $rows
     * @return array<string, mixed>
     */
    private function fold($rows): array
    {
        $payableDays = Money::zero();
        $lopDays = Money::zero();
        $workingDays = Money::zero();
        $presentDays = Money::zero();
        $absentDays = Money::zero();
        $paidLeaveDays = Money::zero();
        $unpaidLeaveDays = Money::zero();

        $weeklyOff = 0;
        $holidays = 0;
        $lateCount = 0;
        $earlyCount = 0;
        $halfDayCount = 0;

        $worked = 0;
        $expected = 0;
        $lateMinutes = 0;
        $earlyMinutes = 0;
        $overtime = 0;

        foreach ($rows as $row) {
            $factor = Money::round((string) $row->payable_factor, 4);

            // R1 — every row contributes, which is why a full month equals the calendar days.
            $payableDays = Money::add($payableDays, $factor);

            $worked += (int) $row->worked_minutes;
            $lateMinutes += (int) $row->late_minutes;
            $earlyMinutes += (int) $row->early_leave_minutes;
            $overtime += (int) $row->overtime_minutes;

            match ($row->day_type) {
                DayType::WeeklyOff => $weeklyOff++,
                DayType::PublicHoliday => $holidays++,
                DayType::Working => null,
            };

            if ($row->day_type === DayType::Working) {
                $workingDays = Money::add($workingDays, '1');
                $expected += (int) $row->expected_minutes;

                // R2 — only a working day can lose pay.
                $lopDays = Money::add($lopDays, Money::sub('1', $factor));
            }

            match (true) {
                $row->status === AttendanceStatus::Late => $lateCount++,
                $row->status === AttendanceStatus::EarlyLeave => $earlyCount++,
                $row->status === AttendanceStatus::HalfDay => $halfDayCount++,
                default => null,
            };

            if ($row->status->isPresentState()) {
                $presentDays = Money::add($presentDays, '1');
            } elseif ($row->status === AttendanceStatus::HalfDay) {
                $presentDays = Money::add($presentDays, '0.5');
            } elseif ($row->status === AttendanceStatus::Absent) {
                $absentDays = Money::add($absentDays, '1');
            } elseif ($row->status === AttendanceStatus::OnLeave) {
                // Paid or unpaid is read from the factor the resolution already decided, not from the
                // leave type — an unpaid day is unpaid whichever rule made it so.
                if (Money::isZero($factor)) {
                    $unpaidLeaveDays = Money::add($unpaidLeaveDays, '1');
                } else {
                    $paidLeaveDays = Money::add($paidLeaveDays, '1');
                }
            }
        }

        $workingDays = Money::round($workingDays, 4);
        $presentDays = Money::round($presentDays, 4);

        return [
            'working_days' => $workingDays,
            'weekly_off_days' => $weeklyOff,
            'holiday_days' => $holidays,
            'present_days' => $presentDays,
            'late_count' => $lateCount,
            'early_leave_count' => $earlyCount,
            'half_day_count' => $halfDayCount,
            'absent_days' => Money::round($absentDays, 4),
            'paid_leave_days' => Money::round($paidLeaveDays, 4),
            'unpaid_leave_days' => Money::round($unpaidLeaveDays, 4),
            'payable_days' => Money::round($payableDays, 4),
            'lop_days' => Money::round($lopDays, 4),
            'worked_minutes' => $worked,
            'expected_minutes' => $expected,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyMinutes,
            'overtime_minutes' => $overtime,
            'attendance_percentage' => Money::isZero($workingDays)
                ? '0.0000'
                : Money::round(Money::percentageOf($presentDays, $workingDays), 4),
        ];
    }
}
