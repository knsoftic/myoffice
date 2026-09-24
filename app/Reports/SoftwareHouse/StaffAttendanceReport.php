<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ReportGroup;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Department;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.attendance` - staff attendance, from the monthly summaries (requirement 99).
 *
 * **It reads `attendance_monthly_summaries` and never the raw punches.** That table is Phase 7's
 * own rollup: it already applies the shift, the weekly off, the holiday calendar and the leave
 * ledger, and it is the same row payroll is calculated from. A report that counted `attendance`
 * rows itself would produce a percentage that disagreed with the payslip - and the payslip is the
 * one somebody is paid on.
 *
 * **"Below threshold" reads `hr.minimum_attendance_percentage`**, not a literal, so the report and
 * whatever warns an employee about their attendance cannot disagree about what "below" means.
 */
final class StaffAttendanceReport extends Report
{
    public function key(): string
    {
        return 'sh.attendance';
    }

    public function title(): string
    {
        return 'Staff Attendance';
    }

    public function description(): string
    {
        return 'Present, absent, late and leave days per employee per month, with the attendance percentage payroll uses.';
    }

    public function icon(): string
    {
        return 'calendar-days';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'attendance';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('attendance_monthly_summaries.period_start', 'Month');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('employee', 'Employee'),
            ColumnDefinition::text('period', 'Month'),
            ColumnDefinition::number('present', 'Present', 'sum'),
            ColumnDefinition::number('absent', 'Absent', 'sum'),
            ColumnDefinition::number('late', 'Late', 'sum'),
            ColumnDefinition::number('leave', 'Leave', 'sum'),
            ColumnDefinition::number('half_day', 'Half day', 'sum'),
            ColumnDefinition::number('hours', 'Hours', 'sum'),
            ColumnDefinition::percent('percentage', 'Attendance %'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('department_id', 'Department', static fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::entity('employee_id', 'Employee', 'employees.view_any'),
            FilterDefinition::boolean('below_threshold', 'Below the minimum'),
        ];
    }

    public function groupBy(): array
    {
        return ['employee' => 'Employee', 'period' => 'Month'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = AttendanceMonthlySummary::query()
            ->with('employee:id,name,department_id')
            ->whereBetween('attendance_monthly_summaries.period_start', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ])
            // 9.5 step 4: the summary is the employee's, so the employee's scope is the summary's.
            ->whereHas('employee', static fn ($q) => $q->visibleTo($viewer));

        if ($request->hasFilter('department_id')) {
            $department = (int) $request->filter('department_id');
            $query->whereHas('employee', static fn ($q) => $q->where('department_id', $department));
        }

        if ($request->hasFilter('employee_id')) {
            $query->where('attendance_monthly_summaries.employee_id', (int) $request->filter('employee_id'));
        }

        $below = $request->booleanFilter('below_threshold');

        if ($below !== null) {
            $minimum = (string) setting('hr.minimum_attendance_percentage', '75.0000');

            $below
                ? $query->where('attendance_monthly_summaries.attendance_percentage', '<', $minimum)
                : $query->where('attendance_monthly_summaries.attendance_percentage', '>=', $minimum);
        }

        $rows = [];
        $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0, 'hours' => '0.00'];

        $query->orderBy('attendance_monthly_summaries.id')->chunkById(300, function ($summaries) use (&$rows, &$totals, $columns): void {
            foreach ($summaries as $summary) {
                // Minutes to hours here rather than in the column: the summary stores minutes
                // because that is what the shift arithmetic needs, and a reader wants hours.
                $hours = Money::round(Money::div((string) ($summary->worked_minutes ?? 0), '60'), 2);

                $leave = (float) $summary->paid_leave_days + (float) $summary->unpaid_leave_days;

                $rows[] = $this->row($columns, [
                    'employee' => $summary->employee?->name,
                    'period' => $summary->period_start?->format('M Y'),
                    'present' => $summary->present_days,
                    'absent' => $summary->absent_days,
                    'late' => $summary->late_count,
                    'leave' => $leave,
                    'half_day' => $summary->half_day_count,
                    'hours' => $hours,
                    'percentage' => $summary->attendance_percentage,
                ]);

                $totals['present'] += (float) $summary->present_days;
                $totals['absent'] += (float) $summary->absent_days;
                $totals['late'] += (int) $summary->late_count;
                $totals['leave'] += $leave;
                $totals['half_day'] += (int) $summary->half_day_count;
                $totals['hours'] = Money::add($totals['hours'], $hours);
            }
        }, 'attendance_monthly_summaries.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'monthly attendance summaries'],
        );
    }
}
