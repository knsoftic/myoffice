<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where an invoice stands (`invoices.status`, requirement §31, phase-13 §2.8).
 *
 * **Six cases and no seventh**, and none of them is ever typed: `InvoiceService::recomputeStatus()` is a
 * pure function of five stored facts, so the same answer comes back whether it is asked by a controller,
 * by a refund, or by the nightly job. "Partially paid" is not a case — it is `paid_amount > 0` with a
 * balance still outstanding, which is `partial` or, past the due date, `overdue`.
 *
 * `overdue` deliberately beats `partial`: an invoice that is part-paid and late is a claim somebody has
 * to chase, and a status that called it merely "partial" would keep it out of the list where chasing
 * happens.
 */
enum InvoiceStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Sent = 'sent';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Partial => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Sent => 'sky',
            self::Partial => 'amber',
            self::Paid => 'emerald',
            self::Overdue => 'rose',
            self::Cancelled => 'slate',
        };
    }

    /**
     * May the document still be changed?
     *
     * `sent` and `overdue` are editable because a client who has not paid may reasonably ask for a
     * correction, and the alternative — cancel and re-issue for a typo in a line description — burns an
     * invoice number for nothing. What the service refuses separately is editing an invoice that has
     * **received money**, which is a different question from its status.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::Overdue], true);
    }

    /**
     * Is money still expected against it?
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Sent, self::Partial, self::Overdue], true);
    }

    /**
     * Does the receivables aging report count it? The same three, named separately because the two
     * questions are allowed to diverge later without one silently changing the other.
     */
    public function countsInReceivables(): bool
    {
        return in_array($this, [self::Sent, self::Partial, self::Overdue], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }

    /**
     * A draft is the business thinking out loud. Everything else — including a cancelled invoice — is a
     * document the client is entitled to see, because they may already be holding a copy of it.
     */
    public function isVisibleToClient(): bool
    {
        return $this !== self::Draft;
    }
}
