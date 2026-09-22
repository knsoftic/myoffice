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
 * Where the money went, by category (§98, phase-13 §8.15).
 *
 * The same rows the expense report draws, in the same order, so clicking through from this card lands
 * on a table that agrees with the chart to the paisa.
 */
final class ExpensesByCategoryChartWidget extends Widget
{
    private const SLICES = 8;

    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_expenses_by_category';
    }

    public function title(): string
    {
        return 'Where the money went';
    }

    public function icon(): string
    {
        return 'chart-pie';
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

    public function span(): int
    {
        return 2;
    }

    public function sort(): int
    {
        return 80;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.reports.finance.expenses');
    }

    public function emptyMessage(): ?string
    {
        return 'No approved expenses in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $viewer = request() instanceof Request ? request()->user() : null;

        try {
            $result = $this->reports->report(FinanceReportType::Expenses, $range, $viewer);
        } catch (Throwable) {
            return ['available' => false, 'labels' => [], 'values' => [], 'slices' => []];
        }

        $rows = $result->rows;
        $head = array_slice($rows, 0, self::SLICES);
        $tail = array_slice($rows, self::SLICES);

        $labels = array_map(static fn (array $row): string => (string) $row['category'], $head);
        $values = array_map(static fn (array $row): string => (string) $row['amount'], $head);

        // Everything past the eighth slice is one "other" wedge rather than silently dropped — a
        // truncated chart that does not say it was truncated reads as the whole picture.
        if ($tail !== []) {
            $labels[] = sprintf('Other (%d)', count($tail));
            $values[] = Money::sum(array_column($tail, 'amount'));
        }

        return [
            'available' => true,
            'labels' => $labels,
            'values' => $values,
            'slices' => array_map(
                static fn (string $label, string $amount): array => ['label' => $label, 'amount' => $amount],
                $labels,
                $values,
            ),
            'total' => Money::of((string) ($result->totals['amount'] ?? Money::ZERO)),
            'range_label' => $range->label(),
        ];
    }
}
