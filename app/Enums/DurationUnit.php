<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Illuminate\Support\Str;

/**
 * The unit a course's length is quoted in (`courses.duration_unit`, requirement §62).
 *
 * The institute writes "3 months" or "40 hours" on its own posters, so the catalogue stores the number
 * and the unit the institute chose rather than normalising everything to minutes and rendering a
 * figure nobody recognises. `minutes()` exists for the one place a comparison is needed.
 */
enum DurationUnit: string
{
    use HasOptions;

    case Hours = 'hours';
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';

    public function label(): string
    {
        return match ($this) {
            self::Hours => 'Hours',
            self::Days => 'Days',
            self::Weeks => 'Weeks',
            self::Months => 'Months',
        };
    }

    public function color(): string
    {
        return 'slate';
    }

    /**
     * "1 month", "3 months" — the label agrees with the number in front of it.
     */
    public function labelFor(int|float|string|null $value): string
    {
        $value = (int) $value;

        return Str::plural(rtrim(strtolower($this->label()), 's'), $value === 0 ? 2 : $value);
    }

    /**
     * A rough length in minutes, for sorting and for "about how long is this".
     *
     * Deliberately approximate — a month is not a fixed number of minutes — and never used to schedule
     * anything. What a class actually occupies is `class_duration_minutes` on a dated session.
     */
    public function approximateMinutes(): int
    {
        return match ($this) {
            self::Hours => 60,
            self::Days => 60 * 24,
            self::Weeks => 60 * 24 * 7,
            self::Months => 60 * 24 * 30,
        };
    }
}
