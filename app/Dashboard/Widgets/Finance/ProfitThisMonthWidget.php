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
 * Income less expenses less collaborator commission, for the range (§98, phase-13 §8.15).
 *
 * Both bottom lines are shown, because they answer different questions: "did the work pay" is before
 * commission, "did the business keep anything" is after it. A card that showed only one would be quoted
 * as the other.
 */
final class ProfitThisMonthWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_profit';
    }

    public function title(): string
    {
        return 'Profit';
    }

    public function icon(): string
    {
        return 'chart-bar';
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
        return 30;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.reports.finance.profit-loss');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $viewer = request() instanceof Request ? request()->user() : null;

        try {
            $result = $this->reports->report(FinanceReportType::ProfitLoss, $range, $viewer);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $net = Money::of((string) ($result->totals['net_profit'] ?? Money::ZERO));

        return [
            'available' => true,
            'income' => Money::of((string) ($result->totals['income'] ?? Money::ZERO)),
            'expenses' => Money::of((string) ($result->totals['expenses'] ?? Money::ZERO)),
            'before_commission' => Money::of((string) ($result->totals['before_commission'] ?? Money::ZERO)),
            'commission' => Money::of((string) ($result->totals['commission_paid'] ?? Money::ZERO)),
            'net' => $net,
            'negative' => Money::isNegative($net),
            'omitted' => $result->omittedSources(),
            'range_label' => $range->label(),
        ];
    }
}
