<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of an attendance deduction.
 *
 *   pending  -> recorded from attendance; payroll will take it from the next slip it builds
 *   applied  -> a locked payroll slip took it — from here the row is evidence and never moves back
 *   reversed -> it no longer applies (attendance corrected, leave approved, rule switched off, or an HR
 *               decision). An applied row is reversed only through a correction slip that refunds it.
 *
 * "Adjusted" is not a status: a pending row whose amount moved stays pending, and the move is in the
 * activity log and in the notification that went out with it.
 */
enum AttendanceDeductionStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Applied = 'applied';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Applied => 'Applied',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Applied => 'rose',
            self::Reversed => 'slate',
        };
    }
}
