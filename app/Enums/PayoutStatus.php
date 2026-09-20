<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a withdrawal stands (`collaborator_payouts.status`, finance spine §3).
 *
 * **A rejected or cancelled payout releases its allocations rather than deleting them.** The named
 * earnings it had reserved go back to available, and the allocation rows stay with a release reason —
 * so "why was my balance 40,000 last Tuesday and 55,000 today" has an answer that does not depend on
 * anybody remembering.
 */
enum PayoutStatus: string
{
    use HasOptions;

    case Requested = 'requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'emerald',
            self::Requested, self::Pending => 'amber',
            self::Approved => 'sky',
            self::Rejected => 'rose',
            self::Cancelled => 'slate',
        };
    }

    /**
     * Is this payout still holding earnings that cannot be spent twice?
     */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Requested, self::Pending, self::Approved], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Rejected, self::Cancelled], true);
    }

    /**
     * Does ending here hand the reserved earnings back?
     */
    public function releasesAllocations(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled], true);
    }

    /**
     * §6.3's transitions, and nothing else is legal.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Pending, self::Approved, self::Rejected, self::Cancelled],
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Paid, self::Rejected, self::Cancelled],
            self::Paid, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
