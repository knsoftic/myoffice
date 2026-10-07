<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DataObjects\Finance\ExpenseSheet;
use App\Enums\ExpenseStatus;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceCategory;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expense Sheet & Analytics (`admin.expense-sheet.index`): the expense register's filtered set, bucketed by
 * day, week and month, pivoted by category, and split by category and by status.
 *
 * **One grouped query, never the rows.** The register's own filtered builder is grouped by
 * `expense_date, finance_category_id, status`, so the result is bounded by the range (at most 366 days,
 * `DateRange::MAX_CUSTOM_DAYS`) times the categories times four statuses, however many expenses there
 * are. Every granularity, the pivot and both splits are folded from that one result in PHP.
 *
 * **What counts as spend.** The trend, the pivot and the category split take Approved and Awaiting
 * approval: a sheet of what is being spent has to show the claims still waiting, and it shows them as
 * their own subtotal so nobody mistakes them for agreed money. Rejected and voided claims are not spend
 * and are left out, unless the Status filter names one, in which case exactly that status is shown (the
 * query has already narrowed to it). The status split always shows every status in the filtered set.
 *
 * **Money only for a reader who may see it.** Without `expenses.view_financial` no `SUM(net_amount)` is
 * selected at all, and every figure is a count of expenses instead.
 *
 * **Weeks are bucketed here, not in SQL**, because `localization.week_start` may be Saturday and MySQL's
 * week functions cannot start a week on one. The first and last week are cut to the range, and their
 * labels say the dates actually covered.
 */
final class ExpenseSheetService
{
    /**
     * The statuses a sheet of spend includes when no Status filter is set.
     */
    public const SPEND_STATUSES = [ExpenseStatus::Approved, ExpenseStatus::Pending];

    public const UNCATEGORISED = 'Uncategorised';

    /**
     * The order statuses are listed in: agreed money first, then what is waiting, then the rest.
     */
    private const STATUS_ORDER = [
        ExpenseStatus::Approved,
        ExpenseStatus::Pending,
        ExpenseStatus::Rejected,
        ExpenseStatus::Voided,
    ];

    /**
     * Category slices before the rest fold into one "Other (n)" slice, as the dashboard's card does.
     */
    private const SLICES = 8;

    /**
     * The pivot column key for an expense with no category, or one whose category row is gone.
     */
    private const NO_CATEGORY = 'none';

    /**
     * @param  Builder<Expense>  $filtered  the register's filtered query (ExpenseController::filtered())
     * @param  string|null  $statusFilter  the Status filter as the request carried it; null or '' when not set
     */
    public function build(Builder $filtered, DateRange $range, bool $seesMoney, ?string $statusFilter): ExpenseSheet
    {
        $zero = $seesMoney ? Money::ZERO : 0;
        $add = $seesMoney
            ? static fn (string|int $left, string|int $right): string => Money::add((string) $left, (string) $right)
            : static fn (string|int $left, string|int $right): int => (int) $left + (int) $right;

        $rows = $this->dailyRows($filtered, $seesMoney);
        $statusFilterSet = $statusFilter !== null && trim($statusFilter) !== '';

        // Every status in the filtered set: the status split and the summary figures.
        $statusTotals = [];
        $statusCounts = [];

        foreach (ExpenseStatus::cases() as $status) {
            $statusTotals[$status->value] = $zero;
            $statusCounts[$status->value] = 0;
        }

        foreach ($rows as $row) {
            $statusTotals[$row['s']->value] = $add($statusTotals[$row['s']->value], $row['v']);
            $statusCounts[$row['s']->value] += $row['n'];
        }

        // Spend: Approved and Awaiting approval, or exactly the filtered status.
        $included = $statusFilterSet
            ? $rows
            : array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['s'], self::SPEND_STATUSES, true),
            ));

        $subtotalStatuses = $this->subtotalStatuses($included, $statusFilterSet, $statusFilter);
        $columns = $this->columns($included, $zero, $add);
        $columnKeys = array_column($columns, 'key');

        $blank = [
            'cells' => array_fill_keys($columnKeys, $zero),
            'statuses' => array_fill_keys(array_map(static fn (ExpenseStatus $s): string => $s->value, $subtotalStatuses), $zero),
            'total' => $zero,
            'count' => 0,
        ];

        $totals = $blank;

        foreach ($included as $row) {
            $totals = $this->accumulate($totals, $row, $add);
        }

        $hasData = $totals['count'] > 0;

        $periods = [];

        foreach ($this->buckets($range) as $period => $buckets) {
            $periods[$period] = $this->period($buckets['buckets'], $buckets['map'], $included, $blank, $subtotalStatuses, $add, $hasData);
        }

        return new ExpenseSheet(
            seesMoney: $seesMoney,
            defaultPeriod: $this->defaultPeriod($range),
            periods: $periods,
            columns: $columns,
            subtotalStatuses: $subtotalStatuses,
            totals: $totals,
            categorySplit: $this->categorySplit($columns, $totals['total'], $zero, $add),
            statusSplit: $this->statusSplit($statusTotals, $statusCounts, $zero, $add),
            statusTotals: $statusTotals,
            statusCounts: $statusCounts,
            expenseCount: array_sum($statusCounts),
            hasData: $hasData,
        );
    }

    /**
     * Day if the range is a month or less, week up to about four months, month beyond that.
     */
    public function defaultPeriod(DateRange $range): string
    {
        $days = $range->days();

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | The one query
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Expense>  $filtered
     * @return list<array{d: string, c: string, id: int|null, name: string, s: ExpenseStatus, n: int, v: string|int}>
     */
    private function dailyRows(Builder $filtered, bool $seesMoney): array
    {
        $query = (clone $filtered)->reorder()->toBase()
            ->groupBy('expense_date', 'finance_category_id', 'status')
            ->selectRaw($seesMoney
                ? 'expense_date as d, finance_category_id as c, status as s, COUNT(*) as n, COALESCE(SUM(net_amount), 0) as total'
                : 'expense_date as d, finance_category_id as c, status as s, COUNT(*) as n');

        $raw = $query->get();

        $ids = $raw->pluck('c')->filter(static fn (mixed $id): bool => $id !== null)
            ->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        // Inactive and deleted categories still name their history; only a missing row is uncategorised.
        $names = $ids === []
            ? []
            : FinanceCategory::withTrashed()->whereKey($ids)->pluck('name', 'id')->all();

        $rows = [];

        foreach ($raw as $row) {
            $status = ExpenseStatus::tryFrom((string) $row->s);

            if ($status === null) {
                continue;
            }

            $id = $row->c === null ? null : (int) $row->c;
            $known = $id !== null && array_key_exists($id, $names);

            $rows[] = [
                'd' => substr((string) $row->d, 0, 10),
                'c' => $known ? 'c'.$id : self::NO_CATEGORY,
                'id' => $known ? $id : null,
                'name' => $known ? (string) $names[$id] : self::UNCATEGORISED,
                's' => $status,
                'n' => (int) $row->n,
                'v' => $seesMoney ? Money::of((string) $row->total) : (int) $row->n,
            ];
        }

        return $rows;
    }

    /*
    |--------------------------------------------------------------------------
    | Folding
    |--------------------------------------------------------------------------
    */

    /**
     * The statuses the trend stacks and the pivot subtotals: Approved and Awaiting approval by default;
     * with a Status filter, whichever statuses survived it (the named one, if nothing did).
     *
     * @param  list<array<string, mixed>>  $included
     * @return list<ExpenseStatus>
     */
    private function subtotalStatuses(array $included, bool $statusFilterSet, ?string $statusFilter): array
    {
        if (! $statusFilterSet) {
            return self::SPEND_STATUSES;
        }

        $present = array_map(static fn (array $row): ExpenseStatus => $row['s'], $included);
        $statuses = array_values(array_filter(
            self::STATUS_ORDER,
            static fn (ExpenseStatus $status): bool => in_array($status, $present, true),
        ));

        if ($statuses === []) {
            $named = ExpenseStatus::tryFrom(strtolower(trim((string) $statusFilter)));

            return $named === null ? [] : [$named];
        }

        return $statuses;
    }

    /**
     * Every category with spend in the range, largest first (ties by name).
     *
     * @param  list<array<string, mixed>>  $included
     * @return list<array{key: string, id: int|null, name: string, total: string|int, count: int}>
     */
    private function columns(array $included, string|int $zero, callable $add): array
    {
        $columns = [];

        foreach ($included as $row) {
            $columns[$row['c']] ??= ['key' => $row['c'], 'id' => $row['id'], 'name' => $row['name'], 'total' => $zero, 'count' => 0];
            $columns[$row['c']]['total'] = $add($columns[$row['c']]['total'], $row['v']);
            $columns[$row['c']]['count'] += $row['n'];
        }

        $columns = array_values($columns);

        usort($columns, static function (array $left, array $right): int {
            $byValue = is_int($left['total'])
                ? $right['total'] <=> $left['total']
                : Money::compare((string) $right['total'], (string) $left['total']);

            return $byValue !== 0 ? $byValue : strcasecmp($left['name'], $right['name']);
        });

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $into
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function accumulate(array $into, array $row, callable $add): array
    {
        $into['cells'][$row['c']] = $add($into['cells'][$row['c']], $row['v']);

        if (array_key_exists($row['s']->value, $into['statuses'])) {
            $into['statuses'][$row['s']->value] = $add($into['statuses'][$row['s']->value], $row['v']);
        }

        $into['total'] = $add($into['total'], $row['v']);
        $into['count'] += $row['n'];

        return $into;
    }

    /**
     * Every day of the range walked once, assigned to its day, week and month bucket.
     *
     * @return array<string, array{buckets: array<string, array{key: string, label: string, from: string, to: string}>, map: array<string, string>}>
     */
    private function buckets(DateRange $range): array
    {
        $days = $range->dateKeys();
        $weekStartsOn = Format::weekStartsOn();

        // A range that crosses a new year names the year, or "06 Oct" would mean two different days.
        $dayFormat = $days !== [] && substr($days[0], 0, 4) !== substr($days[array_key_last($days)], 0, 4)
            ? 'd M Y'
            : 'd M';

        $out = [
            'day' => ['buckets' => [], 'map' => []],
            'week' => ['buckets' => [], 'map' => []],
            'month' => ['buckets' => [], 'map' => []],
        ];

        foreach ($days as $day) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

            $keys = [
                'day' => $day,
                'week' => $date->startOfWeek($weekStartsOn)->toDateString(),
                'month' => substr($day, 0, 7),
            ];

            foreach ($keys as $period => $key) {
                $out[$period]['map'][$day] = $key;
                $out[$period]['buckets'][$key] ??= ['key' => $key, 'label' => '', 'from' => $day, 'to' => $day];
                $out[$period]['buckets'][$key]['to'] = $day;
            }
        }

        foreach ($out as $period => $data) {
            foreach ($data['buckets'] as $key => $bucket) {
                $out[$period]['buckets'][$key]['label'] = match ($period) {
                    'day' => Format::date($bucket['from'], $dayFormat),
                    'week' => $bucket['from'] === $bucket['to']
                        ? Format::date($bucket['from'], $dayFormat)
                        : Format::date($bucket['from'], $dayFormat).' – '.Format::date($bucket['to'], $dayFormat),
                    default => Format::date($bucket['from'], 'M Y'),
                };
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array{key: string, label: string, from: string, to: string}>  $buckets
     * @param  array<string, string>  $map  day => bucket key
     * @param  list<array<string, mixed>>  $included
     * @param  array<string, mixed>  $blank
     * @param  list<ExpenseStatus>  $subtotalStatuses
     * @return array{labels: list<string>, series: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function period(array $buckets, array $map, array $included, array $blank, array $subtotalStatuses, callable $add, bool $hasData): array
    {
        $rows = [];

        foreach ($buckets as $key => $bucket) {
            $rows[$key] = $bucket + $blank;
        }

        foreach ($included as $row) {
            $key = $map[$row['d']] ?? null;

            if ($key !== null) {
                $rows[$key] = $this->accumulate($rows[$key], $row, $add);
            }
        }

        $rows = array_values($rows);

        // No series at all when there is nothing to show, so the chart draws its empty state rather
        // than a flat line of zeros that reads as "nothing was spent" when nothing matched.
        $series = $hasData
            ? array_map(static fn (ExpenseStatus $status): array => [
                'label' => $status->label(),
                'data' => array_map(static fn (array $row): string|int => $row['statuses'][$status->value], $rows),
                'color' => $status->color(),
            ], $subtotalStatuses)
            : [];

        return [
            'labels' => array_column($rows, 'label'),
            'series' => $series,
            'rows' => $rows,
        ];
    }

    /**
     * Top eight categories by value, the rest folded into "Other (n)".
     *
     * @param  list<array{key: string, id: int|null, name: string, total: string|int, count: int}>  $columns
     * @return array{labels: list<string>, values: list<string|int>, slices: list<array{label: string, value: string|int, count: int}>, total: string|int}
     */
    private function categorySplit(array $columns, string|int $total, string|int $zero, callable $add): array
    {
        $columns = array_values(array_filter(
            $columns,
            static fn (array $column): bool => is_int($column['total']) ? $column['total'] > 0 : ! Money::isZero($column['total']),
        ));

        $slices = array_map(
            static fn (array $column): array => ['label' => $column['name'], 'value' => $column['total'], 'count' => $column['count']],
            array_slice($columns, 0, self::SLICES),
        );

        $tail = array_slice($columns, self::SLICES);

        // A truncated chart that does not say it was truncated reads as the whole picture.
        if ($tail !== []) {
            $slices[] = [
                'label' => sprintf('Other (%d)', count($tail)),
                'value' => array_reduce($tail, static fn (string|int $carry, array $column): string|int => $add($carry, $column['total']), $zero),
                'count' => array_sum(array_column($tail, 'count')),
            ];
        }

        return [
            'labels' => array_column($slices, 'label'),
            'values' => array_column($slices, 'value'),
            'slices' => $slices,
            'total' => $total,
        ];
    }

    /**
     * Every status present in the filtered set, coloured as its badge is.
     *
     * @param  array<string, string|int>  $statusTotals
     * @param  array<string, int>  $statusCounts
     * @return array{labels: list<string>, values: list<string|int>, colors: list<string>, slices: list<array<string, mixed>>, total: string|int, count: int}
     */
    private function statusSplit(array $statusTotals, array $statusCounts, string|int $zero, callable $add): array
    {
        $slices = [];
        $total = $zero;

        foreach (self::STATUS_ORDER as $status) {
            if ($statusCounts[$status->value] === 0) {
                continue;
            }

            $slices[] = [
                'status' => $status,
                'label' => $status->label(),
                'value' => $statusTotals[$status->value],
                'count' => $statusCounts[$status->value],
                'color' => $status->color(),
            ];

            $total = $add($total, $statusTotals[$status->value]);
        }

        return [
            'labels' => array_column($slices, 'label'),
            'values' => array_column($slices, 'value'),
            'colors' => array_column($slices, 'color'),
            'slices' => $slices,
            'total' => $total,
            'count' => array_sum(array_column($slices, 'count')),
        ];
    }
}
