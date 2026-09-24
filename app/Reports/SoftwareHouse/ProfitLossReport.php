<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\Enums\FinanceContext;
use App\Enums\FinanceReportType;
use App\Enums\ReportGroup;
use App\Reports\Report;
use App\Reports\SoftwareHouse\Concerns\DelegatesToFinanceReports;

/**
 * `sh.profit_loss` - what came in, what went out, what is left (requirement 99).
 *
 * **Gated on two modules, and that is deliberate.** `module()` can name only one, so `income` is
 * the gate and `expenses.view_reports` is stacked through {@see self::extraPermissions()}. A
 * profit-and-loss built from income the reader may see and expenses they may not would be a net
 * figure that is simply wrong, and wrong in the flattering direction.
 *
 * 9.5 restates phase-13 4 rule 4: an Institute Manager holding `reports.view_reports` is still
 * **403** here. Nothing role-specific enforces that - it falls out of the permission stack, which is
 * the right way round. A role check would have to be maintained; a permission stack maintains itself.
 */
final class ProfitLossReport extends Report
{
    use DelegatesToFinanceReports;

    public function key(): string
    {
        return 'sh.profit_loss';
    }

    public function title(): string
    {
        return 'Profit & Loss';
    }

    public function description(): string
    {
        return 'Cash in, cash out and what is left, with collaborator commission shown as the cost it is.';
    }

    public function icon(): string
    {
        return 'scale';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'income';
    }

    protected function extraPermissions(): array
    {
        return ['expenses.view_reports'];
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('paid_on', 'Received or paid on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('block', 'Block'),
            ColumnDefinition::text('label', 'Line'),
            // No total on this column: the rows are a running statement, and summing a column that
            // already contains its own subtotals would produce a number twice the truth.
            ColumnDefinition::money('amount', 'Amount', 'income', total: null),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('context', 'Context', FinanceContext::options()),
            FilterDefinition::entity('branch_id', 'Branch', 'branches.view_any'),
            FilterDefinition::boolean('compare_previous', 'Compare with the previous period'),
        ];
    }

    protected function financeReportType(): FinanceReportType
    {
        return FinanceReportType::ProfitLoss;
    }
}
