<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a payroll run is for (phase-07 §2.23, §3, requirement §28).
 *
 * {@see allowsNegativeAmounts()} is true only for a `correction` run, and `chk_pri_sign` enforces it in
 * the database: a wrong slip is fixed by a new correction item that references the original (HR-17), and
 * a negative amount outside that context would mean somebody edited paid money.
 */
enum PayrollRunType: string
{
    use HasOptions;

    case Regular = 'regular';
    case Correction = 'correction';
    case Bonus = 'bonus';
    case FinalSettlement = 'final_settlement';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Correction => 'Correction',
            self::Bonus => 'Bonus',
            self::FinalSettlement => 'Final settlement',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Regular => 'sky',
            self::Correction => 'amber',
            self::Bonus => 'violet',
            self::FinalSettlement => 'indigo',
        };
    }

    /**
     * May an item or component on this run carry a negative amount? Corrections only (HR-17).
     */
    public function allowsNegativeAmounts(): bool
    {
        return $this === self::Correction;
    }

    /**
     * Does this run take the one-regular-run-per-period slot (`uq_pr_regular`)?
     */
    public function occupiesRegularSlot(): bool
    {
        return $this === self::Regular;
    }
}
