<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether a payout destination may be paid into (phase-08-09 §3.1, spine §2.15).
 *
 * The spine declares the column as two literals and names no enum. This types it per **D9** without
 * changing the column: a status a controller compares against a string is a status somebody eventually
 * misspells.
 *
 * Disabling is never deletion. A destination that money has already gone to is evidence, and the audit
 * question "where was this payout sent?" has to keep answering.
 */
enum PayoutAccountStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Disabled => 'slate',
        };
    }

    /**
     * May a payout be sent to an account in this state?
     */
    public function isPayable(): bool
    {
        return $this === self::Active;
    }
}
