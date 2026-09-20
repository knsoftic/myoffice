<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of holiday a date is (phase-07 §2.8, §3, requirement §26).
 *
 * {@see changesDayType()} is false for `optional`: an optional holiday is information on the calendar,
 * not a day the business closes, so it must not turn a working day into a non-working one and hand
 * everybody a free payable day.
 */
enum HolidayType: string
{
    use HasOptions;

    case Public = 'public';
    case Religious = 'religious';
    case Company = 'company';
    case Optional = 'optional';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public holiday',
            self::Religious => 'Religious holiday',
            self::Company => 'Company holiday',
            self::Optional => 'Optional holiday',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Public => 'violet',
            self::Religious => 'indigo',
            self::Company => 'sky',
            self::Optional => 'slate',
        };
    }

    /**
     * Does this holiday actually make the day non-working?
     */
    public function changesDayType(): bool
    {
        return $this !== self::Optional;
    }
}
