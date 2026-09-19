<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of one scheduled follow-up (phase-05 §3, `lead_follow_ups.status`).
 *
 * Only `pending` fills the STORED `open_guard` column, and `UNIQUE uq_lfu_open(lead_id, open_guard)` therefore
 * allows at most one open follow-up per lead (§2.3, [D-P5-12]). Every other status leaves the guard NULL, so
 * completed, missed, rescheduled and cancelled rows stack freely.
 */
enum LeadFollowUpStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Completed = 'completed';
    case Missed = 'missed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Completed => 'emerald',
            self::Missed => 'rose',
            self::Rescheduled => 'sky',
            self::Cancelled => 'slate',
        };
    }

    /**
     * The open follow-up — the one `leads.follow_up_at` caches and the one the DB lets exist once per lead.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
