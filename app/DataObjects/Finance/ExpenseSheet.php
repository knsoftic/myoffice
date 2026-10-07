<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Enums\ExpenseStatus;
use App\Support\Money;

/**
 * The Expense Sheet & Analytics screen's data, built by `ExpenseSheetService::build()`.
 *
 * **Every value is either money or a count, never both, and `seesMoney` says which.** A reader with
 * `expenses.view_financial` gets bcmath strings ('1500.00') summed from `net_amount`; anybody else gets
 * integers (how many expenses), and no amount was ever selected for them. The shapes are identical, so
 * a screen renders one or the other with the same markup.
 *
 * Shapes (a "value" is `string` money when `seesMoney`, else `int`):
 *
 *   periods[day|week|month] = [
 *       'labels' => list<string>,                                     the x axis, zero-filled
 *       'series' => list<['label', 'data' => list<value>, 'color']>,  one per subtotal status
 *       'rows'   => list<['key', 'label', 'from', 'to', 'cells' => [columnKey => value],
 *                         'statuses' => [statusValue => value], 'total' => value, 'count' => int]>,
 *   ]
 *   columns  = list<['key', 'id' => ?int, 'name', 'total' => value, 'count' => int]>, largest first
 *   totals   = ['cells' => [columnKey => value], 'statuses' => [statusValue => value],
 *               'total' => value, 'count' => int]
 *   categorySplit = ['labels' => list<string>, 'values' => list<value>,
 *                    'slices' => list<['label', 'value', 'count']>, 'total' => value]
 *   statusSplit   = ['labels', 'values', 'colors' => list<string>,
 *                    'slices' => list<['status' => ExpenseStatus, 'label', 'value', 'count', 'color']>,
 *                    'total' => value, 'count' => int]
 */
final readonly class ExpenseSheet
{
    /**
     * The three granularities, key => the segmented control's label.
     */
    public const PERIODS = [
        'day' => 'Day-wise',
        'week' => 'Week-wise',
        'month' => 'Month-wise',
    ];

    /**
     * key => the first column heading of the pivot and the chart's fallback table.
     */
    public const PERIOD_HEADINGS = [
        'day' => 'Day',
        'week' => 'Week',
        'month' => 'Month',
    ];

    /**
     * @param  array<string, array{labels: list<string>, series: list<array<string, mixed>>, rows: list<array<string, mixed>>}>  $periods
     * @param  list<array{key: string, id: int|null, name: string, total: string|int, count: int}>  $columns
     * @param  list<ExpenseStatus>  $subtotalStatuses
     * @param  array{cells: array<string, string|int>, statuses: array<string, string|int>, total: string|int, count: int}  $totals
     * @param  array<string, mixed>  $categorySplit
     * @param  array<string, mixed>  $statusSplit
     * @param  array<string, string|int>  $statusTotals  every ExpenseStatus value => value
     * @param  array<string, int>  $statusCounts  every ExpenseStatus value => count
     */
    public function __construct(
        public bool $seesMoney,
        public string $defaultPeriod,
        public array $periods,
        public array $columns,
        public array $subtotalStatuses,
        public array $totals,
        public array $categorySplit,
        public array $statusSplit,
        public array $statusTotals,
        public array $statusCounts,
        public int $expenseCount,
        public bool $hasData,
    ) {}

    public static function isPeriod(mixed $period): bool
    {
        return is_string($period) && array_key_exists($period, self::PERIODS);
    }

    /**
     * @return array{labels: list<string>, series: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function period(string $period): array
    {
        return $this->periods[$period] ?? $this->periods[$this->defaultPeriod];
    }

    public function statusTotal(ExpenseStatus $status): string|int
    {
        return $this->statusTotals[$status->value] ?? $this->zero();
    }

    public function statusCount(ExpenseStatus $status): int
    {
        return $this->statusCounts[$status->value] ?? 0;
    }

    /**
     * Money '0.00' and count 0 alike — what a pivot cell prints as a muted dash.
     */
    public function isZero(string|int $value): bool
    {
        return is_int($value) ? $value === 0 : Money::isZero($value);
    }

    public function zero(): string|int
    {
        return $this->seesMoney ? Money::ZERO : 0;
    }
}
