<?php

declare(strict_types=1);

namespace App\Dashboard\Concerns;

/**
 * "vs. the previous period" for a widget that counts things.
 *
 * Counts, not money — `App\Support\Money` owns every arithmetic operation on an amount, and no
 * widget in this phase adds up currency. If a later phase's card compares money, it must build
 * its delta through `Money::sub()` / `Money::percentageOf()` and not through this trait.
 */
trait ComparesRanges
{
    /**
     * @return array{
     *     current: int,
     *     previous: int,
     *     change: int,
     *     percent: float|null,
     *     label: string,
     *     trend: 'up'|'down'|'flat'
     * }
     */
    protected function delta(int $current, int $previous, bool $moreIsBetter = true): array
    {
        $change = $current - $previous;

        $percent = $previous === 0
            ? ($current === 0 ? 0.0 : null)
            : round(($change / $previous) * 100, 1);

        $trend = match (true) {
            $change === 0 => 'flat',
            $change > 0 => $moreIsBetter ? 'up' : 'down',
            default => $moreIsBetter ? 'down' : 'up',
        };

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'percent' => $percent,
            'label' => $this->deltaLabel($change, $percent),
            'trend' => $trend,
        ];
    }

    /**
     * `+12.5%`, `−3`, `no change`, or `new` when there is nothing to compare against.
     */
    protected function deltaLabel(int $change, ?float $percent): string
    {
        if ($change === 0) {
            return 'no change';
        }

        $sign = $change > 0 ? '+' : '−';

        if ($percent === null) {
            // Previous period was zero: a percentage would be meaningless, so state the count.
            return $sign.number_format((float) abs($change));
        }

        return $sign.rtrim(rtrim(number_format(abs($percent), 1), '0'), '.').'%';
    }
}
