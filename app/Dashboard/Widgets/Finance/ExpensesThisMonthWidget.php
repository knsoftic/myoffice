<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Finance;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Services\Finance\FinanceReportService;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Http\Request;
use Throwable;

/**
 * What the business spent over the range — **approved only** (phase-13 §8.15).
 *
 * Pending claims are excluded and counted separately by
 * {@see PendingExpenseApprovalsWidget}. Folding them in would make this figure move every time an
 * employee typed a number, which is not what "what we spent" means.
 */
final class ExpensesThisMonthWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function icon(): string
    {
        return 'receipt-percent';
    }

    public function permission(): ?string
    {
        return 'expenses.view_financial';
    }

    public function module(): ?string
    {
        return 'expenses';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.reports.finance.expenses');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $viewer = request() instanceof Request ? request()->user() : null;

        try {
            $result = $this->reports->report(\App\Enums\FinanceReportType::Expenses, $range, $viewer);
            $previousRange = $range->previous();
            $previous = $this->reports->report(\App\Enums\FinanceReportType::Expenses, $previousRange, $viewer);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $current = Money::of((string) ($result->totals['amount'] ?? Money::ZERO));
        $before = Money::of((string) ($previous->totals['amount'] ?? Money::ZERO));

        return [
            'available' => true,
            'current' => $current,
            'previous' => $before,
            'change' => Money::sub($current, $before),
            'pending_count' => (int) ($result->meta['pending_count'] ?? 0),
            'pending_amount' => Money::of((string) ($result->meta['pending_amount'] ?? Money::ZERO)),
            'range_label' => $range->label(),
            'previous_label' => $previousRange->label(),
        ];
    }
}
