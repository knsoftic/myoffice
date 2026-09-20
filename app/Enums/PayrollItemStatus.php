<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of one salary slip (phase-07 §2.24, §3).
 *
 * `on_hold` exists so a single disputed slip does not block a whole run: the run locks, everybody else
 * is paid, and the held item carries a reason until it is resolved by a correction.
 */
enum PayrollItemStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Locked = 'locked';
    case OnHold = 'on_hold';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Locked => 'Locked',
            self::OnHold => 'On hold',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Locked => 'violet',
            self::OnHold => 'amber',
            self::Paid => 'emerald',
            self::Cancelled => 'rose',
        };
    }


    /**
     * Is every money column on this item frozen (HR-15)?
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * May this item be paid out?
     */
    public function isPayable(): bool
    {
        return $this === self::Locked;
    }
}
