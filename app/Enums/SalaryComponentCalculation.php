<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a salary component works out its amount (phase-07 §2.20, §3, §6.6).
 *
 * {@see pass()} is what makes `percentage_of_gross` possible at all: gross is not known until every
 * other earning has been computed, so those components run in a **second pass** (§6.6 step 3). Without
 * two passes a percentage-of-gross component would silently compute against a partial gross.
 */
enum SalaryComponentCalculation: string
{
    use HasOptions;

    case Fixed = 'fixed';
    case PercentageOfBasic = 'percentage_of_basic';
    case PercentageOfGross = 'percentage_of_gross';
    case PerDay = 'per_day';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::PercentageOfBasic => 'Percentage of basic',
            self::PercentageOfGross => 'Percentage of gross',
            self::PerDay => 'Per day',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Fixed => 'slate',
            self::PercentageOfBasic => 'sky',
            self::PercentageOfGross => 'violet',
            self::PerDay => 'amber',
        };
    }

    public function needsRate(): bool
    {
        return $this !== self::Fixed;
    }

    /**
     * Which pass of §6.6 this component is computed in. Everything is pass 1 except a percentage of
     * gross, which cannot be known until pass 1 has finished.
     */
    public function pass(): int
    {
        return $this === self::PercentageOfGross ? 2 : 1;
    }
}
