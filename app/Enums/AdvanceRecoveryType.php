<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a slice of an advance came back (phase-07 §2.22, §3).
 *
 * `waiver` is the one that is not money at all — it is the business deciding not to collect, which is why
 * it needs a note and why it counts against the same ceiling as a real recovery (HR-19).
 */
enum AdvanceRecoveryType: string
{
    use HasOptions;

    case Payroll = 'payroll';
    case Manual = 'manual';
    case Waiver = 'waiver';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Payroll => 'From payroll',
            self::Manual => 'Paid back directly',
            self::Waiver => 'Waived',
            self::Correction => 'Correction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Payroll => 'sky',
            self::Manual => 'emerald',
            self::Waiver => 'amber',
            self::Correction => 'violet',
        };
    }

    /**
     * Does this need a written note? Waiving money and correcting a recovery both do (HR-20).
     */
    public function requiresNote(): bool
    {
        return $this === self::Waiver || $this === self::Correction;
    }
}
