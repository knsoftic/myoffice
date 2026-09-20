<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a commission promise stands (`collaborator_commission_entitlements.status`, finance spine §3).
 *
 * `fully_released` means every rupee promised has been handed over as ledger entries — the promise is
 * spent, not cancelled. `closed` means the document ended with some of the promise unspent, which is
 * what happens when a student stops paying: the partner keeps what was released and the rest simply
 * never becomes anything.
 */
enum EntitlementStatus: string
{
    use HasOptions;

    case Open = 'open';
    case FullyReleased = 'fully_released';
    case Closed = 'closed';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::FullyReleased => 'Fully released',
            self::Closed => 'Closed',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::FullyReleased => 'emerald',
            self::Closed, self::Superseded => 'slate',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Can this promise still release commission?
     */
    public function canRelease(): bool
    {
        return $this === self::Open;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }
}
