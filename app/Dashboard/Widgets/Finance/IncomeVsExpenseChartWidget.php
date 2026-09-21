<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Finance;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Services\Finance\FinanceReportService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Throwable;

/**
 * Twelve months of money in against money out (§98, phase-13 §8.15).
 *
 * Fixed at twelve months rather than following the dashboard's range selector: the point of this card
 * is the shape of the year, and a trend line that reset to "this week" every time somebody changed the
 * filter would show a single bar.
 */
final class IncomeVsExpenseChartWidget extends Widget
{
    private const MONTHS = 12;

    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_income_vs_expense';
    }

    public function title(): string
    {
        return 'Income against expenses';
    }

    public function icon(): string
    {
        return 'presentation-chart-line';
    }

    public function subtitle(): ?string
    {
        return 'The last 12 months, on a cash basis';
    }

    public function permission(): ?string
    {
        return 'income.view_financial';
    }

    public function module(): ?string
    {
        return 'income';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function span(): int
    {
        return 2;
    }

    public function sort(): int
    {
        return 70;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.reports.finance.profit-loss');
    }

    public function emptyMessage(): ?string
    {
        return 'No money has moved in the last twelve months.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            return $this->reports->incomeVsExpenseByMonth(
                self::MONTHS,
                request() instanceof Request ? request()->user() : null,
            );
        } catch (Throwable) {
            return ['available' => false, 'labels' => [], 'income' => [], 'expenses' => [], 'profit' => []];
        }
    }
}
