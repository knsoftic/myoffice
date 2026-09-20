<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Who asked for an attendance correction (phase-07 §2.10, §3).
 *
 * An employee's own request may need approval — `hr.attendance_correction_requires_approval` decides —
 * while HR changing a row is the approval.
 */
enum CorrectionSource: string
{
    use HasOptions;

    case SelfRequest = 'self_request';
    case HrDirect = 'hr_direct';

    public function label(): string
    {
        return match ($this) {
            self::SelfRequest => 'Employee request',
            self::HrDirect => 'HR correction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SelfRequest => 'sky',
            self::HrDirect => 'violet',
        };
    }


    /**
     * Does a correction from this source wait for somebody to approve it?
     */
    public function needsApproval(): bool
    {
        return $this === self::SelfRequest
            && (bool) setting('hr.attendance_correction_requires_approval', true);
    }
}
