<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where App\Support\Money::distribute() puts the rounding remainder when an amount will not
 * divide evenly (phase-01 §2/§3, decision F-4.11).
 *
 * Splitting money always leaves a residual: 100.00 over three parts is 33.33 + 33.33 + 33.33 and
 * one stray 0.01. This enum is the only answer to "which share gets it", so a split is never
 * ambiguous and the applied rule can be snapshotted onto the row that records the split.
 *
 * Examples for `Money::distribute('100.00', 3, …)`:
 *   · First   → 33.34 + 33.33 + 33.33
 *   · Last    → 33.33 + 33.33 + 33.34
 *   · Largest → the residual joins the largest share (the first one, on an even split)
 */
enum RemainderPlacement: string
{
    use HasOptions;

    /** The remainder joins the first share — Money::distribute()'s default. */
    case First = 'first';

    /** The remainder joins the final share, e.g. the last installment of a plan. */
    case Last = 'last';

    /** The remainder joins the largest share, so the smallest shares stay clean. */
    case Largest = 'largest';

    public function label(): string
    {
        return match ($this) {
            self::First => 'First share',
            self::Last => 'Last share',
            self::Largest => 'Largest share',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::First => 'sky',
            self::Last => 'violet',
            self::Largest => 'emerald',
        };
    }

    /**
     * Short description of the rule, for settings help text and audit screens.
     */
    public function description(): string
    {
        return match ($this) {
            self::First => 'Rounding remainder is added to the first share.',
            self::Last => 'Rounding remainder is added to the last share.',
            self::Largest => 'Rounding remainder is added to the largest share.',
        };
    }
}
