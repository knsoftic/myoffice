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
 * `sh.income` - money received, by where it came from (requirement 99).
 *
 * Every figure is Phase 13's. See {@see DelegatesToFinanceReports} for why this class shapes
 * nothing of its own.
 */
final class IncomeReport extends Report
{
    use DelegatesToFinanceReports;

    public function key(): string
    {
        return 'sh.income';
    }

    public function title(): string
    {
        return 'Income';
    }

    public function description(): string
    {
        return 'Money actually received in the period, broken down by source - project payments, student fees and other income.';
    }

    public function icon(): string
    {
        return 'arrow-trending-up';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'income';
    }

    public function dateFilter(): ?DateFilter
    {
        // **Received, not raised.** An income report dated on the invoice would count money the
        // business has not been given, which is the single most common way a cash report lies.
        return new DateFilter('paid_on', 'Received on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('source', 'Source'),
            ColumnDefinition::number('entries', 'Entries', 'sum'),
            ColumnDefinition::money('amount', 'Amount', 'income'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('context', 'Context', FinanceContext::options()),
            FilterDefinition::entity('branch_id', 'Branch', 'branches.view_any'),
        ];
    }

    protected function financeReportType(): FinanceReportType
    {
        return FinanceReportType::Income;
    }
}
