<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Models\User;
use App\Reports\Report;
use App\Reports\Report as BaseReport;
use App\Support\ReportResult;

/**
 * `sh.employees` - the staff directory (requirement 99).
 *
 * **`Employee::visibleTo($viewer)` is 9.5 step 4** - Phase 7's scope, which already knows that a
 * line manager sees their own reports and an HR officer sees a branch.
 *
 * **Tenure is derived, salary is not shown.** 99's column list for this report stops at tenure;
 * `current_gross_salary` belongs to `sh.payroll`, where it is gated by `payroll.view_financial`.
 * Putting it here as well would have made the staff directory a salary list, which is a different
 * report with a different audience.
 */
final class EmployeesReport extends BaseReport
{
    public function key(): string
    {
        return 'sh.employees';
    }

    public function title(): string
    {
        return 'Employees';
    }

    public function description(): string
    {
        return 'The staff directory: department, designation, employment type, status and how long each person has been here.';
    }

    public function icon(): string
    {
        return 'identification';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'employees';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('employees.joining_date', 'Joined on', [
            'employees.exit_date' => 'Left on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('employee', 'Employee'),
            ColumnDefinition::text('code', 'Code'),
            ColumnDefinition::text('department', 'Department'),
            ColumnDefinition::text('designation', 'Designation'),
            ColumnDefinition::badge('type', 'Type'),
            ColumnDefinition::date('joining', 'Joined'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::number('tenure', 'Tenure (months)'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', EmployeeStatus::options()),
            FilterDefinition::multiselect('type', 'Employment type', EmploymentType::options()),
            FilterDefinition::select('department_id', 'Department', static fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('designation_id', 'Designation', static fn (): array => Designation::query()->orderBy('title')->pluck('title', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function groupBy(): array
    {
        return ['department' => 'Department', 'designation' => 'Designation', 'status' => 'Status', 'type' => 'Type'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Employee::query()
            ->visibleTo($viewer)
            ->with(['department:id,name', 'designation:id,title'])
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        if ($request->hasFilter('status')) {
            $query->whereIn('employees.status', (array) $request->filter('status'));
        }

        if ($request->hasFilter('type')) {
            $query->whereIn('employees.employment_type', (array) $request->filter('type'));
        }

        foreach (['department_id', 'designation_id', 'branch_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('employees.'.$filter, (int) $request->filter($filter));
            }
        }

        $rows = [];

        $query->orderBy('employees.id')->chunkById(200, function ($employees) use (&$rows, $columns): void {
            foreach ($employees as $employee) {
                $rows[] = $this->row($columns, [
                    'employee' => $employee->name,
                    'code' => $employee->employee_code,
                    'department' => $employee->department?->name,
                    'designation' => $employee->designation?->title,
                    'type' => $employee->employment_type?->label(),
                    'joining' => app_date($employee->joining_date),
                    'status' => $employee->status?->label(),
                    // Measured to the exit date when there is one: somebody who left in March has
                    // not been accruing tenure since.
                    'tenure' => $employee->joining_date === null
                        ? null
                        : (int) $employee->joining_date->diffInMonths($employee->exit_date ?? now(), absolute: true),
                ]);
            }
        }, 'employees.id', 'id');

        return new ReportResult(rows: $rows, meta: ['basis' => 'staff directory']);
    }
}
