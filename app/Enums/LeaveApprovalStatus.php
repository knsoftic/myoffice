<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * One level of a leave approval chain (phase-07 §2.17, §3).
 *
 * `skipped` is a real outcome, not a missing answer: when a level has nobody to fill it — no reporting
 * manager, or the approver is the requester — the level is recorded as skipped so the chain still reads
 * as a complete history rather than appearing to be stuck.
 */
enum LeaveApprovalStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Skipped => 'Skipped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
            self::Skipped => 'slate',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
