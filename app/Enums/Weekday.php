<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A day of the week (`timetable_entries.day_of_week`, §71).
 *
 * **It exists because `localization.week_start` is a setting.** A timetable grid that hard-coded
 * Monday as its first column would be wrong for an institute whose week starts on Sunday or Saturday,
 * and `ordered()` is the one place that rotation is computed — five views of §71 read it, so they
 * cannot disagree about which day is first.
 *
 * `isoNumber()` matches `CarbonInterface::dayOfWeekIso` (Monday 1 … Sunday 7), so a date and a rule
 * are compared through one number rather than two string spellings.
 */
enum Weekday: string
{
    use HasOptions;

    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    public function label(): string
    {
        return match ($this) {
            self::Monday => 'Monday',
            self::Tuesday => 'Tuesday',
            self::Wednesday => 'Wednesday',
            self::Thursday => 'Thursday',
            self::Friday => 'Friday',
            self::Saturday => 'Saturday',
            self::Sunday => 'Sunday',
        };
    }

    /** The grid colours every column the same; the token exists because the contract asks for one. */
    public function color(): string
    {
        return $this->isWeekend() ? 'amber' : 'slate';
    }

    public function short(): string
    {
        return substr($this->label(), 0, 3);
    }

    /** ISO-8601: Monday is 1, Sunday is 7 — the same numbering Carbon uses. */
    public function isoNumber(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

    /**
     * Friday and Saturday, or Saturday and Sunday? That depends on the country, so this answers the
     * narrower question the UI actually asks: is it one of the two days most institutes rest on.
     */
    public function isWeekend(): bool
    {
        return $this === self::Saturday || $this === self::Sunday;
    }

    public static function fromDate(\Carbon\CarbonInterface $date): self
    {
        return match ($date->dayOfWeekIso) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            default => self::Sunday,
        };
    }

    /**
     * The seven days rotated so the configured first day leads — the column order of every §71 view.
     *
     * An unknown or absent setting falls back to Monday rather than throwing: a timetable that will
     * not render because somebody typed a bad day is worse than one that renders in the usual order.
     *
     * @return list<self>
     */
    public static function ordered(?string $weekStart = null): array
    {
        $weekStart ??= (string) setting('localization.week_start', 'monday');
        $first = self::tryFrom(strtolower(trim($weekStart))) ?? self::Monday;

        $days = self::cases();
        $offset = array_search($first, $days, true) ?: 0;

        return array_merge(array_slice($days, $offset), array_slice($days, 0, $offset));
    }
}
