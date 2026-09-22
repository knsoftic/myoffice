<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Finance;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\FinanceReportType;
use App\Services\Finance\FinanceReportService;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Http\Request;
use Throwable;

/**
 * Money actually received over the range, against the period before it (§98, phase-13 §8.15).
 *
 * The figure is the income report's own total, so this card and the report can never disagree — a card
 * with its own `SUM()` is a second answer to the same question, and nobody would know which was right
 * (D28).
 *
 * It counts **cash**, not invoices. An invoice is a claim; billing a client is not revenue until they
 * pay, and a card that mixed the two would read as the best month the business ever had.
 */
final class RevenueThisMonthWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_revenue';
    }

    public function title(): string
    {
        return 'Revenue';
    }

    public function icon(): string
    {
        return 'banknotes';
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

    public function sort(): int
    {
        return 10;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.reports.finance.income');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $viewer = request() instanceof Request ? request()->user() : null;

        try {
            $current = Money::of((string) ($this->reports
                ->report(FinanceReportType::Income, $range, $viewer)->totals['amount'] ?? Money::ZERO));

            $previousRange = $range->previous();
            $previous = Money::of((string) ($this->reports
                ->report(FinanceReportType::Income, $previousRange, $viewer)->totals['amount'] ?? Money::ZERO));
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'current' => $current,
            'previous' => $previous,
            'change' => Money::sub($current, $previous),
            'range_label' => $range->label(),
            'previous_label' => $previousRange->label(),
        ];
    }
}
