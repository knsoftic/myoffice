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
 * `sh.expenses` - approved spending, by category (requirement 99).
 *
 * Every figure is Phase 13's. See {@see DelegatesToFinanceReports}.
 */
final class ExpensesReport extends Report
{
    use DelegatesToFinanceReports;

    public function key(): string
    {
        return 'sh.expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function description(): string
    {
        return 'Approved expenses paid in the period, grouped by category.';
    }

    public function icon(): string
    {
        return 'arrow-trending-down';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'expenses';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('paid_on', 'Paid on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('category', 'Category'),
            ColumnDefinition::number('entries', 'Entries', 'sum'),
            ColumnDefinition::money('amount', 'Amount', 'expenses'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('context', 'Context', FinanceContext::options()),
            FilterDefinition::entity('project_id', 'Project', 'projects.view_any'),
            FilterDefinition::entity('branch_id', 'Branch', 'branches.view_any'),
        ];
    }

    protected function financeReportType(): FinanceReportType
    {
        return FinanceReportType::Expenses;
    }
}
