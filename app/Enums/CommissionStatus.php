<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where one commission entry stands (`collaborator_commission_ledger_entries.status`, finance spine §3).
 *
 * The three that matter to a partner: `pending` is earned but unapproved, `available` is theirs to ask
 * for, and `paid` is gone. `approved` sits between the first two because a business may approve on
 * Monday and release on the hold date; `countsInBalance()` is what decides which of them the wallet adds
 * up, and it is the single expression of that rule.
 */
enum CommissionStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Available = 'available';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Available => 'Available',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'emerald',
            self::Pending, self::Approved => 'amber',
            self::Paid => 'sky',
            self::Reversed => 'rose',
            self::Cancelled => 'slate',
        };
    }

    /**
     * Does this entry appear in what the partner is owed?
     *
     * A reversed or cancelled row does not — but it is **not deleted**: the negative row that reversed
     * it is what the ledger sums, and the original stays so that the reversal has something to point at.
     */
    public function countsInBalance(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Available, self::Paid], true);
    }

    /**
     * Can a payout consume this entry?
     */
    public function isPayable(): bool
    {
        return $this === self::Available;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Reversed, self::Cancelled], true);
    }

    /**
     * Is this waiting for somebody to decide?
     */
    public function awaitsApproval(): bool
    {
        return $this === self::Pending;
    }
}
