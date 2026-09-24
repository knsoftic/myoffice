<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\PayrollItemStatus;
use App\Enums\ReportGroup;
use App\Models\Hr\Department;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.payroll` - what was paid, from the run items themselves (requirement 99).
 *
 * **Every column is read from `payroll_run_items`, not recomputed.** That table is the payslip: it
 * is append-only, it holds a `calculation_snapshot` of how each figure was reached, and it is what
 * an employee was actually paid. Recalculating from the salary structure would produce today's
 * answer to a question that was settled in March - and the two differ every time a structure has
 * been edited since.
 *
 * **Ten money columns, one permission.** All of them carry `payroll.view_financial`, so a reader
 * without it sees the run, the employee and the status and no figures at all. That is the intended
 * shape: knowing that somebody was paid in March is an administrative fact, and knowing how much is
 * not.
 */
final class PayrollReport extends Report
{
    public function key(): string
    {
        return 'sh.payroll';
    }

    public function title(): string
    {
        return 'Payroll';
    }

    public function description(): string
    {
        return 'Every payslip in the period: basic, allowances, bonus, commission, deductions, advance recovery, tax and net.';
    }

    public function icon(): string
    {
        return 'banknotes';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'payroll';
    }

    public function dateFilter(): ?DateFilter
    {
        // The run's period, not the row's `created_at`: a March payslip corrected in May is still a
        // March payslip, and dating it on the correction would move money between months.
        return new DateFilter('payroll_runs.period_start', 'Payroll month', [
            'payroll_run_items.paid_at' => 'Paid on',
        ]);
    }

    public function columns(): array
    {
        $money = static fn (string $key, string $label): ColumnDefinition => ColumnDefinition::money($key, $label, 'payroll');

        return [
            ColumnDefinition::text('run', 'Run'),
            ColumnDefinition::text('employee', 'Employee'),
            ColumnDefinition::text('department', 'Department'),
            ColumnDefinition::badge('status', 'Status'),
            $money('basic', 'Basic'),
            $money('allowances', 'Allowances'),
            $money('bonus', 'Bonus'),
            $money('commission', 'Commission'),
            $money('deductions', 'Deductions'),
            $money('advance', 'Advance recovery'),
            $money('tax', 'Tax'),
            $money('net', 'Net'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('payroll_run_id', 'Run', static fn (): array => PayrollRun::query()
                ->orderByDesc('period_start')
                ->limit(50)
                ->get()
                ->mapWithKeys(static fn (PayrollRun $run): array => [
                    (string) $run->getKey() => $run->period_start?->format('M Y').' - '.($run->run_type?->label() ?? ''),
                ])
                ->all()),
            FilterDefinition::select('department_id', 'Department', static fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('status', 'Status', PayrollItemStatus::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['run' => 'Run', 'department' => 'Department', 'status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = PayrollRunItem::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_items.payroll_run_id')
            ->with('employee:id,name')
            ->select('payroll_run_items.*')
            ->addSelect('payroll_runs.period_start as run_period')
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()])
            // 9.5 step 4 again: a payslip is an employee's, so it inherits the employee's scope.
            ->whereHas('employee', static fn ($q) => $q->visibleTo($viewer));

        if ($request->hasFilter('payroll_run_id')) {
            $query->where('payroll_run_items.payroll_run_id', (int) $request->filter('payroll_run_id'));
        }

        if ($request->hasFilter('status')) {
            $query->whereIn('payroll_run_items.status', (array) $request->filter('status'));
        }

        if ($request->hasFilter('department_id')) {
            $department = (int) $request->filter('department_id');
            $query->whereHas('employee', static fn ($q) => $q->where('department_id', $department));
        }

        $moneyKeys = ['basic', 'allowances', 'bonus', 'commission', 'deductions', 'advance', 'tax', 'net'];
        $rows = [];
        $totals = array_fill_keys($moneyKeys, Money::ZERO);

        $query->orderBy('payroll_run_items.id')->chunkById(200, function ($items) use (&$rows, &$totals, $columns, $moneyKeys): void {
            foreach ($items as $item) {
                $figures = [
                    'basic' => (string) $item->basic_salary,
                    'allowances' => (string) $item->allowance_amount,
                    'bonus' => (string) $item->bonus_amount,
                    'commission' => (string) $item->commission_amount,
                    'deductions' => (string) $item->total_deductions,
                    'advance' => (string) $item->advance_recovery_amount,
                    'tax' => (string) $item->tax_amount,
                    'net' => (string) $item->net_salary,
                ];

                $rows[] = $this->row($columns, [
                    'run' => $item->getAttribute('run_period') !== null
                        ? \Carbon\CarbonImmutable::parse((string) $item->getAttribute('run_period'))->format('M Y')
                        : null,
                    'employee' => $item->employee?->name,
                    'department' => $item->department_name,
                    'status' => $item->status?->label(),
                    ...$figures,
                ]);

                foreach ($moneyKeys as $key) {
                    $totals[$key] = Money::add($totals[$key], $figures[$key] ?: '0');
                }
            }
        }, 'payroll_run_items.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'payroll run items'],
        );
    }
}
