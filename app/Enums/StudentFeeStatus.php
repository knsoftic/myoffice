<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a fee charge stands (`student_fees.status`, finance spine §3).
 *
 * **`overpaid` is a real state, not an error.** A student who pays a round number against an odd balance
 * leaves the charge over-settled, and the overpayment is carried rather than refused — but it earns no
 * commission (`CommissionSkipReason::OverpaymentOnly`), because a partner brought in the fee, not the
 * rounding.
 */
enum StudentFeeStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Partial => 'Partly paid',
            self::Paid => 'Paid',
            self::Overpaid => 'Overpaid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'emerald',
            self::Partial, self::Pending => 'amber',
            self::Overpaid => 'sky',
            self::Overdue, self::Refunded => 'rose',
            self::Cancelled => 'slate',
        };
    }

    /**
     * Can this charge still take money?
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Partial, self::Overdue], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded], true);
    }
}
