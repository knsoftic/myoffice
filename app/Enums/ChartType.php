<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a report's chart is drawn (phase-19-23 §6.20 `chart()`, §98).
 *
 * The nine analytics charts of §6.22 name exactly four shapes between them — line, bar, stacked bar
 * and combo — and the three that follow are here because a report's own `chart()` may want them.
 * The set is deliberately small: a chart type nobody has asked for is a branch in the renderer that
 * has never been looked at.
 *
 * **`Combo` is the only one with two axes**, and it exists for one question the ticket report asks:
 * counts created and resolved as bars, with a breach *rate* as a line over them. Plotting a rate on
 * a count axis would flatten it to nothing, and plotting it separately would lose the comparison
 * that is the whole point.
 */
enum ChartType: string
{
    use HasOptions;

    /** A trend over time. */
    case Line = 'line';

    /** Discrete categories side by side. */
    case Bar = 'bar';

    /** Parts of a whole, per category — submitted / late / missed per batch. */
    case StackedBar = 'stacked_bar';

    /** Bars on the left axis, a line on the right. */
    case Combo = 'combo';

    /** A filled trend. Used when the total matters as much as the shape. */
    case Area = 'area';

    /** Shares of one whole. */
    case Pie = 'pie';

    case Doughnut = 'doughnut';

    public function label(): string
    {
        return match ($this) {
            self::Line => 'Line',
            self::Bar => 'Bar',
            self::StackedBar => 'Stacked bar',
            self::Combo => 'Bars and a line',
            self::Area => 'Area',
            self::Pie => 'Pie',
            self::Doughnut => 'Doughnut',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Line, self::Area => 'sky',
            self::Bar, self::StackedBar => 'indigo',
            self::Combo => 'violet',
            self::Pie, self::Doughnut => 'emerald',
        };
    }

    /** Does this type need a second, right-hand axis? */
    public function hasSecondaryAxis(): bool
    {
        return $this === self::Combo;
    }

    /** Are the series drawn on top of one another rather than beside? */
    public function isStacked(): bool
    {
        return $this === self::StackedBar;
    }

    /**
     * Does this type plot shares of a single total?
     *
     * A pie or a doughnut with more than one series is meaningless, and the renderer refuses rather
     * than drawing the first series and dropping the rest.
     */
    public function isProportional(): bool
    {
        return $this === self::Pie || $this === self::Doughnut;
    }

    /** The underlying chart.js primitive, for the one place that has to name it. */
    public function primitive(): string
    {
        return match ($this) {
            self::Line, self::Combo => 'line',
            self::Bar, self::StackedBar => 'bar',
            self::Area => 'line',
            self::Pie => 'pie',
            self::Doughnut => 'doughnut',
        };
    }
}
