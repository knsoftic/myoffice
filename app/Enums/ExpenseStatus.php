<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a recorded expense stands (`expenses.status`, requirement §30, phase-13 §2.6).
 *
 * **Only an approved expense counts in a report.** That is the whole point of the approval column: a
 * pending claim is somebody's assertion, not yet the company's money, and a profit-and-loss statement
 * that included it would move every time an employee typed a number. `countsInReports()` is the single
 * expression of that rule, and every aggregate in §6.7 reads it.
 */
enum ExpenseStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Voided => 'Voided',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
            self::Voided => 'slate',
        };
    }

    public function countsInReports(): bool
    {
        return $this === self::Approved;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Voided], true);
    }

    /**
     * Only a claim nobody has acted on may be deleted. Once it has been approved it is a decision, and
     * once it has been rejected or voided it is the record of one — the correct undoing of an approved
     * expense is a `finance_reversals` row, not an absence.
     */
    public function isDeletable(): bool
    {
        return $this === self::Pending;
    }
}
