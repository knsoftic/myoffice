<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of day a calendar date is for one employee (phase-07 §2.9, §3, requirement §26).
 *
 * Resolved once by `WorkCalendarService` and then **snapshotted onto the attendance row** (HR-2), so
 * changing a shift or adding a holiday later can never rewrite what a past day was.
 */
enum DayType: string
{
    use HasOptions;

    case Working = 'working';
    case WeeklyOff = 'weekly_off';
    case PublicHoliday = 'public_holiday';

    public function label(): string
    {
        return match ($this) {
            self::Working => 'Working day',
            self::WeeklyOff => 'Weekly off',
            self::PublicHoliday => 'Public holiday',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Working => 'sky',
            self::WeeklyOff => 'slate',
            self::PublicHoliday => 'violet',
        };
    }

    public function isWorking(): bool
    {
        return $this === self::Working;
    }

    /**
     * Does this day belong in the month's "working days" denominator? Only a working day does — which is
     * what stops a weekend or a public holiday from ever being counted as absence.
     */
    public function countsTowardWorkingDays(): bool
    {
        return $this === self::Working;
    }
}
