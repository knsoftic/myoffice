<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a commission promise is worked out (`projects.commission_type`).
 *
 * **Declared here, by Phase 6, with the cases the finance spine's §3 specifies verbatim** (spine F-5.5,
 * phase-06 §3): Phase 6 migrates before the spine, `projects.commission_type` casts to it, and two
 * declarations of one `App\Enums` name would be a merge conflict rather than a style question. Phases
 * 10-12, 13, 14-17 and 18 **reuse** this enum and must not create a second one.
 *
 * `manual` exists for adjustments only — a hand-entered amount that no rule produced.
 */
enum CommissionCalculationType: string
{
    use HasOptions;

    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount',
            self::Manual => 'Manual adjustment',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Percentage => 'sky',
            self::Fixed => 'violet',
            self::Manual => 'amber',
        };
    }

    /**
     * Does this type need a rate (`decimal(8,4)`, `10.0000` = 10 %)?
     */
    public function requiresRate(): bool
    {
        return $this === self::Percentage;
    }

    /**
     * Does this type need a fixed amount (`decimal(15,2)`)?
     */
    public function requiresFixedAmount(): bool
    {
        return $this === self::Fixed;
    }

    /**
     * Was the figure produced by a rule, or typed by a human?
     */
    public function isRuleDriven(): bool
    {
        return $this !== self::Manual;
    }
}
