<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What became of money that was received (`student_fee_payments.status`, `project_payments.status`,
 * finance spine §3).
 *
 * **`countsAsReceived()` is the whole of "money actually received"** (`CLAUDE.md` rule 5). The commission
 * engine asks this and nothing else at guard step 3, so there is one place that decides whether a
 * receipt earns — and a bounced cheque, which felt like money for a week, answers false.
 *
 * `partially_refunded` still counts: the remaining part is real, and the refunded part is undone by its
 * own reversing ledger row rather than by pretending the receipt never happened.
 */
enum ReceivedPaymentStatus: string
{
    use HasOptions;

    case Cleared = 'cleared';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Cleared => 'Cleared',
            self::PartiallyRefunded => 'Partly refunded',
            self::Refunded => 'Refunded',
            self::Voided => 'Voided',
            self::Bounced => 'Bounced',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cleared => 'emerald',
            self::PartiallyRefunded => 'amber',
            self::Refunded, self::Bounced => 'rose',
            self::Voided => 'slate',
        };
    }

    /**
     * Is this money the business actually has? The single expression of `CLAUDE.md` rule 5.
     */
    public function countsAsReceived(): bool
    {
        return in_array($this, [self::Cleared, self::PartiallyRefunded], true);
    }

    /**
     * Is every rupee of this receipt gone?
     */
    public function isFullyUndone(): bool
    {
        return in_array($this, [self::Refunded, self::Voided, self::Bounced], true);
    }
}
