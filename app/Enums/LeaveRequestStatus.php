<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a leave request stands (phase-07 §2.15, §3, requirement §27 plus `cancelled`).
 *
 * The two balance methods are the important ones and they are deliberately different: a **pending**
 * request *reserves* days so two overlapping requests cannot both fit inside one quota, and only an
 * **approved** one *consumes* them. That is why a rejection releases the reservation rather than
 * refunding a consumption (HR-7).
 */
enum LeaveRequestStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
            self::Cancelled => 'slate',
        };
    }


    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Does this status hold days aside, without having spent them?
     */
    public function reservesBalance(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Does this status actually spend the days?
     */
    public function consumesBalance(): bool
    {
        return $this === self::Approved;
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Cancelled;
    }
}
