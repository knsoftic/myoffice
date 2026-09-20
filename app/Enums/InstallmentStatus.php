<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where one line of a payment schedule stands (`student_fee_installments.status`, finance spine §3).
 *
 * **`waived` is not `cancelled`.** A waived line was forgiven — somebody decided the student does not owe
 * it — and stays on the schedule as evidence of that decision. A cancelled line should never have existed.
 */
enum InstallmentStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Waived = 'waived';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Partial => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Waived => 'Waived',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'emerald',
            self::Pending, self::Partial => 'amber',
            self::Overdue => 'rose',
            self::Waived => 'sky',
            self::Cancelled => 'slate',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Partial, self::Overdue], true);
    }

    public function countsTowardSchedule(): bool
    {
        return $this !== self::Cancelled;
    }
}
