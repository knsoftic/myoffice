<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Throwable;

/**
 * An inclusive window of time, as a value object (phase-02 §3).
 *
 * The dashboard's range selector, every report, every chart and every "vs. previous period" delta
 * speak this one type. It is immutable — every factory and `previous()` return a new instance,
 * nothing mutates — and timezone aware: the boundaries are built in the display timezone the
 * `localization.timezone` setting names, while `apply()` hands the database boundaries in UTC, the
 * storage timezone (D61), so a range never silently slides by five hours.
 *
 *   $range = DateRange::month();
 *   $range->start;                     // 2026-09-01 00:00:00 Asia/Karachi
 *   $range->end;                       // 2026-09-30 23:59:59 Asia/Karachi
 *   $range->previous()->label();       // 'August 2026'
 *
 * ---------------------------------------------------------------------------------------------
 * The `scopeInRange()` convention
 * ---------------------------------------------------------------------------------------------
 *
 * A model that can be filtered by a range publishes one scope, and the scope delegates — it never
 * builds its own `whereBetween`:
 *
 *   public function scopeInRange(Builder $query, DateRange $range, string $column = 'created_at'): Builder
 *   {
 *       return $range->apply($query, $column);
 *   }
 *
 * For a `date` column (`transaction_date`, `due_date`, `paid_on`) the scope calls
 * `$range->applyDates($query, $column)` instead, which compares dates to dates and so never loses
 * a row to a time component that is not there.
 */
final class DateRange
{
    public const TODAY = 'today';

    public const YESTERDAY = 'yesterday';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const QUARTER = 'quarter';

    public const YEAR = 'year';

    public const CUSTOM = 'custom';

    /** What `make()` falls back to when the request asks for nothing or for nonsense. */
    public const DEFAULT_PRESET = self::MONTH;

    /**
     * Every preset a selector may offer, in display order.
     *
     * @var list<string>
     */
    public const PRESETS = [
        self::TODAY,
        self::YESTERDAY,
        self::WEEK,
        self::MONTH,
        self::QUARTER,
        self::YEAR,
        self::CUSTOM,
    ];

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $preset,
        public readonly string $timezone,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Factories
    |--------------------------------------------------------------------------
    */

    public static function today(?string $timezone = null): self
    {
        $now = self::now($timezone);

        return new self($now->startOfDay(), $now->endOfDay(), self::TODAY, $now->timezoneName);
    }

    public static function yesterday(?string $timezone = null): self
    {
        $day = self::now($timezone)->subDay();

        return new self($day->startOfDay(), $day->endOfDay(), self::YESTERDAY, $day->timezoneName);
    }

    /**
     * The current week, starting on the day `localization.week_start` names.
     */
    public static function week(?string $timezone = null): self
    {
        $now = self::now($timezone);
        $start = $now->startOfWeek(Format::weekStartsOn());

        return new self($start, $start->addDays(6)->endOfDay(), self::WEEK, $now->timezoneName);
    }

    public static function month(?string $timezone = null): self
    {
        $now = self::now($timezone);

        return new self($now->startOfMonth(), $now->endOfMonth(), self::MONTH, $now->timezoneName);
    }

    public static function quarter(?string $timezone = null): self
    {
        $now = self::now($timezone);

        return new self($now->startOfQuarter(), $now->endOfQuarter(), self::QUARTER, $now->timezoneName);
    }

    public static function year(?string $timezone = null): self
    {
        $now = self::now($timezone);

        return new self($now->startOfYear(), $now->endOfYear(), self::YEAR, $now->timezoneName);
    }

    /**
     * An explicit window. Both ends are inclusive whole days, and they are swapped when they
     * arrive the wrong way round rather than returning an empty range nobody can explain.
     *
     * @param  mixed  $from  anything Format::carbon() can read
     * @param  mixed  $to  anything Format::carbon() can read
     */
    public static function custom(mixed $from, mixed $to, ?string $timezone = null): self
    {
        $timezone = self::resolveTimezone($timezone);

        $start = self::boundary($from, $timezone);
        $end = self::boundary($to, $timezone);

        if ($start === null && $end === null) {
            throw new InvalidArgumentException('DateRange::custom(): neither end of the range is a readable date.');
        }

        $start ??= $end;
        $end ??= $start;

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start->startOfDay(), $end->endOfDay(), self::CUSTOM, $timezone);
    }

    /**
     * The last `$days` days including today — the shape a trend chart wants.
     *
     * A convenience over custom(): `lastDays(14)` is the 14-day login trend, and its `previous()`
     * is the 14 days before that.
     */
    public static function lastDays(int $days, ?string $timezone = null): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('DateRange::lastDays(): a range needs at least one day.');
        }

        $now = self::now($timezone);

        return self::custom($now->subDays($days - 1), $now, $now->timezoneName);
    }

    /**
     * Build a range from request input: `?range=month` or `?range=custom&from=…&to=…`.
     *
     * Unknown presets and unreadable dates fall back to the default preset, so a hand-edited URL
     * can never 500 a dashboard.
     */
    public static function make(?string $preset = null, mixed $from = null, mixed $to = null, ?string $timezone = null): self
    {
        $preset = strtolower(trim((string) $preset));

        if ($preset === self::CUSTOM || ($preset === '' && ($from !== null || $to !== null))) {
            try {
                return self::custom($from, $to, $timezone);
            } catch (Throwable) {
                return self::default($timezone);
            }
        }

        return match ($preset) {
            self::TODAY => self::today($timezone),
            self::YESTERDAY => self::yesterday($timezone),
            self::WEEK => self::week($timezone),
            self::MONTH => self::month($timezone),
            self::QUARTER => self::quarter($timezone),
            self::YEAR => self::year($timezone),
            default => self::default($timezone),
        };
    }

    /**
     * The range a screen opens on.
     */
    public static function default(?string $timezone = null): self
    {
        return self::make(self::DEFAULT_PRESET, null, null, $timezone);
    }

    /**
     * value => label for a range selector.
     *
     * @return array<string, string>
     */
    public static function presets(): array
    {
        return [
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::WEEK => 'This week',
            self::MONTH => 'This month',
            self::QUARTER => 'This quarter',
            self::YEAR => 'This year',
            self::CUSTOM => 'Custom range',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The comparison window
    |--------------------------------------------------------------------------
    */

    /**
     * The window immediately before this one, for a delta.
     *
     * A calendar preset steps back one calendar unit — the previous month of a 31-day month is the
     * 30-day month before it, which is what "vs. last month" means to a business. A custom range
     * steps back its own length, so a 7-day window compares against the 7 days before it and the
     * two windows are always the same size.
     */
    public function previous(): self
    {
        return match ($this->preset) {
            self::TODAY, self::YESTERDAY => $this->shiftDays(1),
            self::WEEK => $this->shiftDays(7),
            self::MONTH => $this->previousCalendar('month'),
            self::QUARTER => $this->previousCalendar('quarter'),
            self::YEAR => $this->previousCalendar('year'),
            default => $this->shiftDays($this->days()),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the range
    |--------------------------------------------------------------------------
    */

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function preset(): string
    {
        return $this->preset;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function isCustom(): bool
    {
        return $this->preset === self::CUSTOM;
    }

    /**
     * Whole days covered, both ends included: a single day is 1.
     */
    public function days(): int
    {
        return (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }

    /**
     * Every day in the range as 'Y-m-d' — the x-axis of a trend chart, including the days with no
     * rows, so a gap renders as a zero instead of disappearing.
     *
     * @return list<string>
     */
    public function dateKeys(): array
    {
        $keys = [];
        $cursor = $this->start->startOfDay();
        $last = $this->end->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $keys[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $keys;
    }

    /**
     * Is this moment inside the range?
     */
    public function contains(mixed $date): bool
    {
        $moment = Format::carbon($date, $this->timezone);

        return $moment !== null && $moment->betweenIncluded($this->start, $this->end);
    }

    public function equals(self $other): bool
    {
        return $this->start->equalTo($other->start) && $this->end->equalTo($other->end);
    }

    /**
     * A human label: 'Today', 'September 2026', '1 Sep 2026 – 14 Sep 2026'.
     */
    public function label(): string
    {
        return match ($this->preset) {
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::WEEK => Format::date($this->start).' – '.Format::date($this->end),
            self::MONTH => $this->start->format('F Y'),
            self::QUARTER => 'Q'.$this->start->quarter.' '.$this->start->format('Y'),
            self::YEAR => $this->start->format('Y'),
            default => $this->days() === 1
                ? Format::date($this->start)
                : Format::date($this->start).' – '.Format::date($this->end),
        };
    }

    /**
     * The selector state and the query string in one array — also the shape a widget's JSON
     * response reports its range with.
     *
     * @return array{preset: string, from: string, to: string, label: string, days: int, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->start->toDateString(),
            'to' => $this->end->toDateString(),
            'label' => $this->label(),
            'days' => $this->days(),
            'timezone' => $this->timezone,
        ];
    }

    /**
     * Query parameters that rebuild this exact range through `make()`.
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        $parameters = ['range' => $this->preset];

        if ($this->isCustom()) {
            $parameters['from'] = $this->start->toDateString();
            $parameters['to'] = $this->end->toDateString();
        }

        return $parameters;
    }

    public function __toString(): string
    {
        return $this->label();
    }

    /*
    |--------------------------------------------------------------------------
    | Applying the range to a query
    |--------------------------------------------------------------------------
    */

    /**
     * Constrain a `datetime` / `timestamp` column to this range.
     *
     * The boundaries are converted into the timezone Eloquent stores timestamps in before they are
     * bound, so a range built in the user's timezone still selects the right rows.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(EloquentBuilder|QueryBuilder|Relation $query, string $column = 'created_at'): EloquentBuilder|QueryBuilder|Relation
    {
        $query->whereBetween($column, [
            $this->storageStart()->format('Y-m-d H:i:s'),
            $this->storageEnd()->format('Y-m-d H:i:s'),
        ]);

        return $query;
    }

    /**
     * Constrain a `date` column to this range — dates compared to dates, no time component.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyDates(EloquentBuilder|QueryBuilder|Relation $query, string $column): EloquentBuilder|QueryBuilder|Relation
    {
        $query->whereBetween($column, [
            $this->start->toDateString(),
            $this->end->toDateString(),
        ]);

        return $query;
    }

    /**
     * The inclusive start, in the timezone timestamps are stored in.
     */
    public function storageStart(): CarbonImmutable
    {
        return $this->toStorageTimezone($this->start);
    }

    /**
     * The inclusive end, in the timezone timestamps are stored in.
     */
    public function storageEnd(): CarbonImmutable
    {
        return $this->toStorageTimezone($this->end);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Move the whole window back by `$days`.
     *
     * The preset travels with the window so a chain of previous() calls keeps stepping by the same
     * unit — except for the two presets that name a specific day: the day before today is
     * "yesterday", and the day before that is a plain dated range, not a second "yesterday".
     */
    private function shiftDays(int $days): self
    {
        return new self(
            $this->start->subDays($days)->startOfDay(),
            $this->end->subDays($days)->endOfDay(),
            match ($this->preset) {
                self::TODAY => self::YESTERDAY,
                self::YESTERDAY => self::CUSTOM,
                default => $this->preset,
            },
            $this->timezone,
        );
    }

    /**
     * The previous whole calendar month, quarter or year.
     */
    private function previousCalendar(string $unit): self
    {
        $start = match ($unit) {
            'month' => $this->start->subMonthNoOverflow()->startOfMonth(),
            'quarter' => $this->start->subQuarterNoOverflow()->startOfQuarter(),
            default => $this->start->subYearNoOverflow()->startOfYear(),
        };

        $end = match ($unit) {
            'month' => $start->endOfMonth(),
            'quarter' => $start->endOfQuarter(),
            default => $start->endOfYear(),
        };

        return new self($start, $end, $this->preset, $this->timezone);
    }

    /**
     * One end of a custom range, read in the range's own timezone.
     *
     * A **string** is what a person typed or picked ('2026-09-14', '2026-09-14 09:00'), so it is
     * wall-clock time in the display timezone and is parsed there directly. Parsing it in the
     * process timezone (UTC, D61) first and converting afterwards moved a date-only input onto the
     * previous day for every zone west of UTC — '14 Sep' in New York became 13 Sep 20:00. A
     * `DateTimeInterface` or a unix timestamp is already an instant, so it is converted, not
     * re-read. A string carrying its own offset keeps that offset, as Carbon always does.
     */
    private static function boundary(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return Format::carbon($value, $timezone);
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function toStorageTimezone(CarbonImmutable $moment): CarbonImmutable
    {
        $timezone = self::storageTimezone();

        try {
            return $moment->setTimezone($timezone);
        } catch (Throwable) {
            return $moment;
        }
    }

    /**
     * The timezone Eloquent writes timestamps in — `config('app.timezone')`, which is `UTC` and
     * which nothing changes at runtime (D61). `localization.timezone` is display-only and is never
     * the storage timezone.
     */
    private static function storageTimezone(): string
    {
        try {
            $timezone = config('app.timezone');
        } catch (Throwable) {
            return Format::FALLBACK_TIMEZONE;
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : Format::FALLBACK_TIMEZONE;
    }

    private static function now(?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::resolveTimezone($timezone));
    }

    private static function resolveTimezone(?string $timezone): string
    {
        if ($timezone === null || trim($timezone) === '') {
            return Format::timezone();
        }

        return trim($timezone);
    }
}
