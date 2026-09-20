<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a manual attendance change was for (phase-07 §2.10, §3, HR-6).
 */
enum AttendanceCorrectionType: string
{
    use HasOptions;

    case MissingCheckIn = 'missing_check_in';
    case MissingCheckOut = 'missing_check_out';
    case WrongTime = 'wrong_time';
    case StatusChange = 'status_change';
    case LeaveRegularisation = 'leave_regularisation';
    case HolidayRecalculation = 'holiday_recalculation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MissingCheckIn => 'Missing check-in',
            self::MissingCheckOut => 'Missing check-out',
            self::WrongTime => 'Wrong time',
            self::StatusChange => 'Status change',
            self::LeaveRegularisation => 'Leave regularisation',
            self::HolidayRecalculation => 'Holiday recalculation',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MissingCheckIn => 'amber',
            self::MissingCheckOut => 'amber',
            self::WrongTime => 'orange',
            self::StatusChange => 'violet',
            self::LeaveRegularisation => 'sky',
            self::HolidayRecalculation => 'teal',
            self::Other => 'slate',
        };
    }
}
