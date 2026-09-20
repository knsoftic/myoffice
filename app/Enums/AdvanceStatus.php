<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of a salary advance (phase-07 §2.21, §3, requirement §28).
 *
 * {@see isRecoverable()} is what payroll asks: only a `disbursed` or `recovering` advance takes a
 * recovery line out of a slip. `written_off` is a decision somebody made and recorded, never a quiet
 * disappearance — `recovered + waived <= amount` is a CHECK (HR-19).
 */
enum AdvanceStatus: string
{
    use HasOptions;

    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disbursed = 'disbursed';
    case Recovering = 'recovering';
    case Settled = 'settled';
    case WrittenOff = 'written_off';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Disbursed => 'Disbursed',
            self::Recovering => 'Recovering',
            self::Settled => 'Settled',
            self::WrittenOff => 'Written off',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'amber',
            self::Approved => 'sky',
            self::Rejected => 'rose',
            self::Disbursed => 'violet',
            self::Recovering => 'indigo',
            self::Settled => 'emerald',
            self::WrittenOff => 'zinc',
            self::Cancelled => 'slate',
        };
    }


    /**
     * Should payroll take a recovery line for this advance?
     */
    public function isRecoverable(): bool
    {
        return $this === self::Disbursed || $this === self::Recovering;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Settled, self::WrittenOff, self::Cancelled], true);
    }
}
