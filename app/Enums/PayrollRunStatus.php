<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of a payroll run (phase-07 §2.23, §3, §6.7).
 *
 * **`locked` is a one-way door.** From there every money column, component row and snapshot is immutable
 * (HR-15), the period's attendance is locked with it (HR-18), and the only way to change an outcome is a
 * correction run. There is deliberately no `unlock`: an unlock is an edit of paid money with extra
 * steps.
 */
enum PayrollRunStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Generated = 'generated';
    case Locked = 'locked';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Generated => 'Generated',
            self::Locked => 'Locked',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Generated => 'sky',
            self::Locked => 'violet',
            self::PartiallyPaid => 'amber',
            self::Paid => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Is the money on this run frozen? Locked, and everything after it.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::Locked, self::PartiallyPaid, self::Paid], true);
    }

    /**
     * May the run still be regenerated or edited? Only before it locks.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Generated;
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }
}
