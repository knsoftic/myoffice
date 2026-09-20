<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a payout gave back the earnings it had reserved
 * (`collaborator_payout_allocations.release_reason`, finance spine §3).
 *
 * The allocation row is **never deleted** — it is released, with one of these on it. A deleted
 * allocation would make the wallet's arithmetic come out right while losing the only evidence that the
 * money was ever reserved, and "my balance moved and nobody can tell me why" is the exact failure this
 * column exists to prevent.
 */
enum AllocationReleaseReason: string
{
    use HasOptions;

    case PayoutRejected = 'payout_rejected';
    case PayoutCancelled = 'payout_cancelled';
    case PayoutReturned = 'payout_returned';
    case ReleasedForReversal = 'released_for_reversal';

    public function label(): string
    {
        return match ($this) {
            self::PayoutRejected => 'The payout was rejected',
            self::PayoutCancelled => 'The payout was cancelled',
            self::PayoutReturned => 'The transfer came back',
            self::ReleasedForReversal => 'The commission was reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PayoutRejected => 'rose',
            self::PayoutCancelled => 'slate',
            self::PayoutReturned => 'amber',
            self::ReleasedForReversal => 'violet',
        };
    }

    /**
     * Does the earning go back to available, or is it gone too?
     *
     * A reversal is the one that does not: the commission itself was undone, so there is nothing to
     * hand back.
     */
    public function returnsToAvailable(): bool
    {
        return $this !== self::ReleasedForReversal;
    }
}
