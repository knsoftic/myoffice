<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceDeductionType;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollRunType;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceDeduction;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\PayrollRunItemComponent;
use App\Support\Hr\PayrollPeriod;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "My Salary & Deductions" — an employee's own finalized payroll, read back month by month, year by year
 * and day by day.
 *
 * **Read only, and nothing is recalculated.** Every amount here is a stored figure of a finalized slip: the
 * item's own columns, its own lines (`payroll_run_item_components`), the attendance deductions that slip
 * took (`attendance_deductions.payroll_run_item_id`), and the correction slips issued against it. The
 * day-by-day history only *distributes* a stored line over the days it was charged for — the unpaid-leave
 * line over the days that lost pay, at the slip's own per-day amount — and shows any rounding remainder
 * as its own row, so the history always adds up to the slip.
 *
 * **Finalized only.** A slip is shown once its run is locked (or paid): a draft or generated run is still
 * being worked on by HR and can change, so the employee sees its status but none of its figures.
 */
final class MySalaryService
{
    /** Lines the day-by-day history explains by date rather than repeating as month-level rows. */
    private const DATED_CODES = ['ATT_LATE', 'ATT_ABSENCE', 'UNPAID_LEAVE'];

    /**
     * The month the page opens on: the latest finalized slip's, or the current month when there is none.
     */
    public function defaultMonth(Employee $employee): Carbon
    {
        $latest = $this->finalizedItems($employee)
            ->filter(fn (PayrollRunItem $item): bool => $item->run_type === PayrollRunType::Regular)
            ->sortByDesc(fn (PayrollRunItem $item): string => sprintf('%04d-%02d', $item->run->period_year, $item->run->period_month))
            ->first();

        return $latest === null
            ? Carbon::today()->startOfMonth()
            : Carbon::create((int) $latest->run->period_year, (int) $latest->run->period_month, 1)->startOfDay();
    }

    /**
     * One month: its status, and — once finalized — the slip, the corrections, the attendance behind it,
     * the deduction lines and the dated history.
     *
     * @return array<string, mixed>
     */
    public function month(Employee $employee, int $year, int $month): array
    {
        $period = PayrollPeriod::of($year, $month);
        $items = $this->itemsFor($employee, $year, $month);

        $slip = $items->first(fn (PayrollRunItem $item): bool => $item->run_type === PayrollRunType::Regular
            && $item->run->status->isLocked());

        $corrections = $items
            ->filter(fn (PayrollRunItem $item): bool => $item->run_type === PayrollRunType::Correction
                && $item->run->status->isLocked())
            ->values();

        $status = $this->status($items, $slip);

        if ($slip === null) {
            return [
                'period' => $period,
                'status' => $status,
                'slip' => null,
                'corrections' => collect(),
                'summary' => null,
                'attendance' => null,
                'lines' => collect(),
                'history' => collect(),
                'categories' => [],
            ];
        }

        $history = $this->history($slip, $corrections, $period);
        $adjustments = Money::round(Money::sum(['0', ...$corrections->map(fn (PayrollRunItem $item): string => (string) $item->net_salary)->all()]));

        return [
            'period' => $period,
            'status' => $status,
            'slip' => $slip,
            'corrections' => $corrections,
            'summary' => [
                'basic' => Money::round((string) $slip->basic_salary),
                'gross' => Money::round((string) $slip->gross_earnings),
                'deductions' => Money::round((string) $slip->total_deductions),
                'net' => Money::round((string) $slip->net_salary),
                'adjustments' => $adjustments,
                'net_after_corrections' => Money::round(Money::add((string) $slip->net_salary, $adjustments)),
                'per_day' => Money::round((string) $slip->per_day_amount),
                'day_divisor' => Money::round((string) $slip->day_divisor, 4),
            ],
            'attendance' => $this->attendanceSummary($slip),
            'lines' => $slip->components
                ->filter(fn (PayrollRunItemComponent $line): bool => $line->side->value === 'deduction')
                ->sortBy('sort_order')
                ->values(),
            'history' => $history,
            'categories' => $this->categories($history),
        ];
    }

    /**
     * One day: that month's status, the attendance row of the day, and the history rows dated that day.
     *
     * @return array<string, mixed>
     */
    public function day(Employee $employee, Carbon $date): array
    {
        $month = $this->month($employee, (int) $date->year, (int) $date->month);

        $attendance = Attendance::query()
            ->with('leaveType:id,name,is_paid')
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        return [
            'date' => $date->copy()->startOfDay(),
            'month' => $month,
            'attendance' => $attendance,
            'rows' => $month['history']
                ->filter(fn (array $row): bool => $row['date'] !== null && $row['date']->isSameDay($date))
                ->values(),
        ];
    }

    /**
     * One year: every month's status and, where finalized, its figures — and the year's totals.
     *
     * @return array{year: int, months: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function year(Employee $employee, int $year): array
    {
        $items = $this->finalizedItems($employee, $year)->merge($this->openItems($employee, $year));
        $months = [];
        $totals = ['gross' => '0', 'deductions' => '0', 'net' => '0', 'adjustments' => '0'];

        for ($month = 1; $month <= 12; $month++) {
            $ofMonth = $items->filter(fn (PayrollRunItem $item): bool => (int) $item->run->period_month === $month)->values();
            $slip = $ofMonth->first(fn (PayrollRunItem $item): bool => $item->run_type === PayrollRunType::Regular && $item->run->status->isLocked());
            $corrections = $ofMonth->filter(fn (PayrollRunItem $item): bool => $item->run_type === PayrollRunType::Correction && $item->run->status->isLocked());
            $adjustments = Money::round(Money::sum(['0', ...$corrections->map(fn (PayrollRunItem $item): string => (string) $item->net_salary)->all()]));

            $row = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('F'),
                'status' => $this->status($ofMonth, $slip),
                'slip' => $slip,
                'gross' => $slip ? Money::round((string) $slip->gross_earnings) : null,
                'deductions' => $slip ? Money::round((string) $slip->total_deductions) : null,
                'net' => $slip ? Money::round((string) $slip->net_salary) : null,
                'adjustments' => $corrections->isEmpty() ? null : $adjustments,
            ];

            if ($slip !== null) {
                $totals['gross'] = Money::add($totals['gross'], $row['gross']);
                $totals['deductions'] = Money::add($totals['deductions'], $row['deductions']);
                $totals['net'] = Money::add($totals['net'], $row['net']);
            }

            if ($corrections->isNotEmpty()) {
                $totals['adjustments'] = Money::add($totals['adjustments'], $adjustments);
            }

            $months[] = $row;
        }

        return [
            'year' => $year,
            'months' => $months,
            'totals' => array_map(static fn (string $amount): string => Money::round($amount), $totals),
        ];
    }

    /**
     * The years the employee has any slip in, newest first, always including the current one.
     *
     * @return list<int>
     */
    public function years(Employee $employee): array
    {
        return PayrollRunItem::query()
            ->where('employee_id', $employee->getKey())
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_items.payroll_run_id')
            ->distinct()
            ->pluck('payroll_runs.period_year')
            ->map(fn ($year): int => (int) $year)
            ->push((int) Carbon::today()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, PayrollRunItem>
     */
    private function itemsFor(Employee $employee, int $year, int $month): Collection
    {
        return PayrollRunItem::query()
            ->with(['run', 'components'])
            ->where('employee_id', $employee->getKey())
            ->whereHas('run', fn ($run) => $run->where('period_year', $year)->where('period_month', $month))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PayrollRunItem>
     */
    private function finalizedItems(Employee $employee, ?int $year = null): Collection
    {
        return PayrollRunItem::query()
            ->with('run')
            ->where('employee_id', $employee->getKey())
            ->whereHas('run', fn ($run) => $run
                ->whereIn('status', ['locked', 'partially_paid', 'paid'])
                ->when($year !== null, fn ($inner) => $inner->where('period_year', $year)))
            ->orderBy('id')
            ->get();
    }

    /**
     * Slips of runs still being worked on — only their existence is shown, never their figures.
     *
     * @return Collection<int, PayrollRunItem>
     */
    private function openItems(Employee $employee, int $year): Collection
    {
        return PayrollRunItem::query()
            ->with('run')
            ->where('employee_id', $employee->getKey())
            ->whereHas('run', fn ($run) => $run
                ->whereIn('status', ['draft', 'generated'])
                ->where('period_year', $year))
            ->orderBy('id')
            ->get();
    }

    /**
     * Pending (nothing yet), Generated (HR is working on it), Finalized, or Paid.
     *
     * @param  Collection<int, PayrollRunItem>  $items
     * @return array{key: string, label: string, color: string}
     */
    private function status(Collection $items, ?PayrollRunItem $slip): array
    {
        if ($slip !== null) {
            return match ($slip->status) {
                PayrollItemStatus::Paid => ['key' => 'paid', 'label' => 'Paid', 'color' => 'emerald'],
                PayrollItemStatus::OnHold => ['key' => 'finalized', 'label' => 'Finalized · on hold', 'color' => 'amber'],
                default => ['key' => 'finalized', 'label' => 'Finalized', 'color' => 'sky'],
            };
        }

        $open = $items->first(fn (PayrollRunItem $item): bool => $item->run_type === PayrollRunType::Regular
            && $item->run->status->isEditable());

        if ($open !== null) {
            return ['key' => 'generated', 'label' => 'Generated · awaiting finalization', 'color' => 'violet'];
        }

        return ['key' => 'pending', 'label' => 'Pending', 'color' => 'slate'];
    }

    /**
     * The attendance the slip was calculated from — frozen on the slip and its locked summary.
     *
     * @return array<string, string|int>
     */
    private function attendanceSummary(PayrollRunItem $slip): array
    {
        $summary = $slip->attendance_monthly_summary_id === null
            ? null
            : AttendanceMonthlySummary::query()->find($slip->attendance_monthly_summary_id);

        return [
            'working_days' => Money::round((string) $slip->working_days, 2),
            'present_days' => Money::round((string) $slip->present_days, 2),
            'absent_days' => $summary === null ? '—' : Money::round((string) $summary->absent_days, 2),
            'late_count' => (int) $slip->late_count,
            'late_minutes' => $summary === null ? 0 : (int) $summary->late_minutes,
            'half_days' => $summary === null ? 0 : (int) $summary->half_day_count,
            'paid_leave_days' => Money::round((string) $slip->paid_leave_days, 2),
            'unpaid_leave_days' => Money::round((string) $slip->unpaid_leave_days, 2),
            'lop_days' => Money::round((string) $slip->lop_days, 2),
            'payable_days' => Money::round((string) $slip->payable_days, 2),
        ];
    }

    /**
     * Every deduction of the month as a row: dated where the deduction belongs to a day, month-level
     * otherwise, plus each finalized correction's lines.
     *
     * @param  Collection<int, PayrollRunItem>  $corrections
     * @return Collection<int, array<string, mixed>>
     */
    private function history(PayrollRunItem $slip, Collection $corrections, PayrollPeriod $period): Collection
    {
        $rows = collect();
        $snapshot = (array) ($slip->calculation_snapshot ?? []);
        $monthDate = $slip->run->payment_date ?? $period->end;

        // 1. The attendance-deduction rule rows this very slip took — one per late arrival or excess absence.
        $taken = AttendanceDeduction::query()
            ->with('attendance:id,late_minutes,status')
            ->where('payroll_run_item_id', $slip->getKey())
            ->orderBy('deduction_date')
            ->orderBy('id')
            ->get();

        foreach ($taken as $deduction) {
            $rows->push($this->ruleRow($deduction, $slip));
        }

        // 2. The unpaid-leave line, distributed over the days that lost pay.
        $unpaid = $slip->components->first(fn (PayrollRunItemComponent $line): bool => $line->component_code === 'UNPAID_LEAVE');

        if ($unpaid !== null) {
            foreach ($this->unpaidRows($slip, $unpaid, $period, (bool) ($snapshot['excess_absence_deduction_enabled'] ?? false), $monthDate) as $row) {
                $rows->push($row);
            }
        }

        // 3. Every other deduction line of the slip, month-level.
        foreach ($slip->components as $line) {
            if ($line->side->value !== 'deduction' || in_array($line->component_code, self::DATED_CODES, true)) {
                continue;
            }

            $rows->push([
                'date' => null,
                'month_date' => $monthDate,
                'kind' => $line->component_code === 'LATE' ? 'late' : 'other',
                'reason' => (string) $line->component_name,
                'details' => (string) ($line->calculation_note ?: $line->component_group->label()),
                'calculation' => $this->lineCalculation($line),
                'amount' => Money::round((string) $line->amount),
                'slip' => $slip->slip_number,
            ]);
        }

        // 4. Corrections: a deduction taken later, or money given back (a refund is a negative deduction).
        foreach ($corrections as $correction) {
            foreach ($correction->components as $line) {
                $amount = Money::round((string) $line->amount);
                $isRefund = $line->side->value === 'earning';

                $rows->push([
                    'date' => null,
                    'month_date' => $correction->created_at ?? $monthDate,
                    'kind' => $isRefund ? 'refund' : 'other',
                    'reason' => $isRefund ? (string) $line->component_name : 'Correction: '.$line->component_name,
                    'details' => (string) ($line->calculation_note ?: 'Correction slip '.$correction->slip_number),
                    'calculation' => sprintf('Correction slip %s against %s.', $correction->slip_number, $slip->slip_number),
                    'amount' => $isRefund ? Money::round(Money::sub('0', $amount)) : $amount,
                    'slip' => $correction->slip_number,
                ]);
            }
        }

        return $rows
            ->sortBy(fn (array $row): string => ($row['date'] ?? $row['month_date'])?->format('Y-m-d').'|'.$row['reason'])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleRow(AttendanceDeduction $deduction, PayrollRunItem $slip): array
    {
        $calc = (array) ($deduction->calculation ?? []);
        $daily = Money::round((string) $deduction->daily_salary);
        $basis = (string) ($calc['day_basis'] ?? '');
        $divisor = isset($calc['divisor']) ? rtrim(rtrim((string) $calc['divisor'], '0'), '.') : null;
        $base = isset($calc['prorate_base']) ? Money::round((string) $calc['prorate_base']) : null;

        $dailyText = $base !== null && $divisor !== null
            ? sprintf('daily salary %s (%s ÷ %s %s)', money($daily), money($base), $divisor, str_replace('_', ' ', $basis ?: 'days'))
            : sprintf('daily salary %s', money($daily));

        if ($deduction->deduction_type === AttendanceDeductionType::LateArrival) {
            $minutes = (int) ($deduction->attendance?->late_minutes ?? 0);

            return [
                'date' => $deduction->deduction_date->copy()->startOfDay(),
                'month_date' => null,
                'kind' => 'late',
                'reason' => 'Late arrival',
                'details' => $minutes > 0 ? sprintf('Late by %d minutes (after the shift start and grace)', $minutes) : 'Marked late',
                'calculation' => sprintf('%s%% of the %s = %s', rtrim(rtrim((string) $deduction->rate_percentage, '0'), '.'), $dailyText, money((string) $deduction->amount)),
                'amount' => Money::round((string) $deduction->amount),
                'slip' => $slip->slip_number,
            ];
        }

        return [
            'date' => $deduction->deduction_date->copy()->startOfDay(),
            'month_date' => null,
            'kind' => 'absence',
            'reason' => 'Absence',
            'details' => sprintf('Unpaid absent day — absence %d of the month, %d allowed without a deduction', (int) $deduction->absence_sequence, (int) $deduction->allowance_days),
            'calculation' => sprintf('A full day: the %s = %s', $dailyText, money((string) $deduction->amount)),
            'amount' => Money::round((string) $deduction->amount),
            'slip' => $slip->slip_number,
        ];
    }

    /**
     * The unpaid-leave line spread over its days at the slip's per-day amount, with any rounding remainder
     * as its own row. If the days do not account for the line's quantity, the line is shown whole instead.
     *
     * @return list<array<string, mixed>>
     */
    private function unpaidRows(PayrollRunItem $slip, PayrollRunItemComponent $line, PayrollPeriod $period, bool $absencesByRule, Carbon $monthDate): array
    {
        $perDay = Money::round((string) ($line->base_amount ?? $slip->per_day_amount));

        $days = Attendance::query()
            ->with('leaveType:id,name,is_paid')
            ->where('employee_id', $slip->employee_id)
            ->whereBetween('attendance_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->where('payable_factor', '<', 1)
            ->orderBy('attendance_date')
            ->get()
            ->reject(fn (Attendance $row): bool => $absencesByRule
                && $row->status === AttendanceStatus::Absent
                && $row->day_type === DayType::Working
                && $row->leave_request_id === null);

        $share = Money::round(Money::sum(['0', ...$days->map(fn (Attendance $row): string => Money::sub('1', (string) $row->payable_factor))->all()]), 4);

        if (Money::compare($share, Money::round((string) $line->quantity, 4)) !== 0) {
            return [[
                'date' => null,
                'month_date' => $monthDate,
                'kind' => 'unpaid_leave',
                'reason' => (string) $line->component_name,
                'details' => (string) ($line->calculation_note ?: 'Unpaid days of the month'),
                'calculation' => $this->lineCalculation($line),
                'amount' => Money::round((string) $line->amount),
                'slip' => $slip->slip_number,
            ]];
        }

        $rows = [];
        $sum = '0';

        foreach ($days as $day) {
            $unpaidShare = Money::round(Money::sub('1', (string) $day->payable_factor), 4);
            $amount = Money::round(Money::mul($perDay, $unpaidShare));
            $sum = Money::add($sum, $amount);
            $shareText = rtrim(rtrim($unpaidShare, '0'), '.');

            [$kind, $reason] = match (true) {
                $day->status === AttendanceStatus::OnLeave => ['unpaid_leave', 'Unpaid leave'],
                $day->status === AttendanceStatus::Absent => ['absence', 'Absence'],
                $day->status === AttendanceStatus::HalfDay => ['unpaid_leave', 'Half day'],
                default => ['unpaid_leave', $day->status->label()],
            };

            $rows[] = [
                'date' => $day->attendance_date->copy()->startOfDay(),
                'month_date' => null,
                'kind' => $kind,
                'reason' => $reason,
                'details' => sprintf('%s unpaid day%s%s', $shareText, $shareText === '1' ? '' : 's', $day->leaveType ? ' · '.$day->leaveType->name : ''),
                'calculation' => sprintf('%s unpaid × per-day salary %s = %s', $shareText, money($perDay), money($amount)),
                'amount' => $amount,
                'slip' => $slip->slip_number,
            ];
        }

        $remainder = Money::round(Money::sub((string) $line->amount, $sum));

        if (Money::compare($remainder, '0') !== 0) {
            $rows[] = [
                'date' => null,
                'month_date' => $monthDate,
                'kind' => 'unpaid_leave',
                'reason' => 'Rounding',
                'details' => 'The unpaid-leave line is rounded once for the month; the days above are rounded one by one.',
                'calculation' => sprintf('%s on the slip − %s across the days', money((string) $line->amount), money($sum)),
                'amount' => $remainder,
                'slip' => $slip->slip_number,
            ];
        }

        return $rows;
    }

    private function lineCalculation(PayrollRunItemComponent $line): string
    {
        $parts = [];

        if ($line->quantity !== null && Money::isPositive((string) $line->quantity)) {
            $parts[] = rtrim(rtrim((string) $line->quantity, '0'), '.').' ×';
        }

        if ($line->base_amount !== null && Money::isPositive((string) $line->base_amount)) {
            $parts[] = money((string) $line->base_amount);
        }

        if ($line->rate !== null && Money::isPositive((string) $line->rate)) {
            $parts[] = '@ '.rtrim(rtrim((string) $line->rate, '0'), '.').'%';
        }

        $formula = trim(implode(' ', $parts));

        return trim(($formula !== '' ? $formula.' = ' : '').money((string) $line->amount).($line->calculation_note ? ' — '.$line->calculation_note : ''));
    }

    /**
     * Totals by the four headings the page leads with.
     *
     * @param  Collection<int, array<string, mixed>>  $history
     * @return array<string, array{label: string, amount: string, count: int}>
     */
    private function categories(Collection $history): array
    {
        $labels = [
            'late' => 'Late arrivals',
            'absence' => 'Absences',
            'unpaid_leave' => 'Unpaid leave & half days',
            'other' => 'Other deductions',
            'refund' => 'Refunds & corrections',
        ];

        $out = [];

        foreach ($labels as $kind => $label) {
            $ofKind = $history->where('kind', $kind);

            $out[$kind] = [
                'label' => $label,
                'amount' => Money::round(Money::sum(['0', ...$ofKind->pluck('amount')->all()])),
                'count' => $ofKind->filter(fn (array $row): bool => $row['reason'] !== 'Rounding')->count(),
            ];
        }

        return $out;
    }
}
