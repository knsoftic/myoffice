<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a rule **version** stands (`collaborator_commission_settings.status`, finance spine §3).
 *
 * The rule table is append-only: a rate is never edited, it is superseded by a new version with its own
 * effective date. That is what makes "what was the rate on the day that payment arrived" answerable
 * years later, which is the only question a commission dispute ever asks.
 */
enum CommissionRuleStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Starts later',
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Scheduled => 'amber',
            self::Superseded, self::Expired => 'slate',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Could a payment dated inside this version's window resolve to it?
     *
     * `cancelled` is the only one that never can: it was withdrawn before it meant anything. A
     * superseded or expired version still governs the payments that fell in its window, which is the
     * whole reason it is kept.
     */
    public function canGovernAPayment(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * Is this the version a new payment would use today?
     */
    public function isCurrent(): bool
    {
        return $this === self::Active;
    }
}
