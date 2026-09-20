<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether a reversal still needs a signature (`payment_reversals.approval_status`, finance spine §3).
 *
 * **Commission is not clawed back until the reversal is approved.** A refund somebody entered and a
 * refund somebody authorised are different facts, and taking money back from a partner on the strength
 * of the first would be a debt raised by a data-entry error. `not_required` exists because a business
 * that does not gate refunds should not be forced to invent an approval step it will then rubber-stamp.
 */
enum ReversalApprovalStatus: string
{
    use HasOptions;

    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'No approval needed',
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotRequired => 'slate',
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
        };
    }

    /**
     * May the engine claw commission back on the strength of this reversal?
     */
    public function allowsCommissionReversal(): bool
    {
        return in_array($this, [self::NotRequired, self::Approved], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::NotRequired, self::Approved, self::Rejected], true);
    }
}
