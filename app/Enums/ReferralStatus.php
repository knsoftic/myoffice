<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where an attribution version stands (`collaborator_referrals.status`, finance spine §3).
 *
 * **At most one `active` row per subject, for the life of the database** (INV-R5) — enforced by the four
 * `uq_cr_*_current` indexes over a generated guard column, not by a service convention. Changing an
 * attribution **supersedes**: the old row stays with `superseded_by_id` pointing at the new one, and no
 * ledger row is ever re-pointed (INV-R4). `revoked` is for an attribution that was simply wrong and
 * should credit nobody.
 */
enum ReferralStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Superseded = 'superseded';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Superseded => 'slate',
            self::Revoked => 'rose',
        };
    }

    /**
     * Can a payment against this subject earn through this row?
     */
    public function earnsCommission(): bool
    {
        return $this === self::Active;
    }

    /**
     * Does this row occupy the subject's one active slot?
     */
    public function occupiesTheCurrentSlot(): bool
    {
        return $this === self::Active;
    }
}
