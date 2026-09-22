<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * One presentation layer for dates, times, numbers and money (phase-02 §3).
 *
 * Every date and every amount rendered anywhere in the system goes through here, so changing
 * `localization.date_format` or `localization.currency` restyles the whole application without a
 * single view being touched. Nothing in a Blade file formats a date with `->format('d/m/Y')` and
 * nothing formats an amount with `number_format()`.
 *
 *   app_date($invoice->issued_on)        // '12 Sep 2026'
 *   app_time($session->starts_at)        // '03:45 PM'
 *   app_datetime($activity->created_at)  // '12 Sep 2026 03:45 PM'
 *   app_number($students->count())       // '1,248'
 *   money($invoice->total_amount)        // 'Rs 12,480.00'
 *
 * Settings are read live — the repository already holds the whole payload in memory, so a read is
 * an array lookup — and every read is wrapped: before the `settings` table exists (a fresh clone,
 * a half-run install) the documented fallbacks apply and a view still renders.
 *
 * Money is never reformatted here: `money()` forwards to `App\Support\Money`, the one canonical
 * money surface, so an amount meets exactly one formatter and never a float.
 */
final class Format
{
    public const FALLBACK_DATE_FORMAT = 'd M Y';

    public const FALLBACK_TIME_FORMAT = 'h:i A';

    public const FALLBACK_TIMEZONE = 'UTC';

    public const FALLBACK_LOCALE = 'en';

    public const FALLBACK_WEEK_START = 'monday';

    /** What a null or unparseable date renders as. */
    public const EMPTY = '';

    /** Carbon day-of-week numbers for the three week starts the settings offer. */
    private const WEEK_STARTS = [
        'sunday' => CarbonInterface::SUNDAY,
        'monday' => CarbonInterface::MONDAY,
        'saturday' => CarbonInterface::SATURDAY,
    ];

    /*
    |--------------------------------------------------------------------------
    | Dates and times
    |--------------------------------------------------------------------------
    */

    /**
     * A date in the configured format: '12 Sep 2026'.
     *
     * Accepts anything a date can arrive as — a Carbon instance, a model datetime, a 'Y-m-d'
     * string, a unix timestamp — and renders the empty string for null, so a view never has to
     * guard the call. A calendar date ('Y-m-d', or a `date`-cast value at midnight) renders as that
     * same date in every display timezone; see carbon().
     */
    public static function date(mixed $value, ?string $format = null): string
    {
        $date = self::carbon($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::dateFormat());
    }

    /**
     * The date of an **instant**, in the configured format and the display timezone.
     *
     * date() reads a DateTimeInterface at exactly 00:00:00 as a calendar date and does not shift
     * it, which is right for a `date` column and wrong for a timestamp: a `created_at` stamped at
     * 00:00:00 UTC is still an instant, and west of UTC it falls on the previous day. Use this for
     * the date half of any timestamp shown beside time(), so the two halves always agree (Phase 2
     * review low 2). A bare 'Y-m-d' string is still a calendar date — it has no time to convert.
     */
    public static function instantDate(mixed $value, ?string $format = null): string
    {
        $date = self::moment($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::dateFormat());
    }

    /**
     * A time in the configured format: '03:45 PM' or '15:45'.
     *
     * A time of day is only ever asked of a moment, so a value at exactly midnight is converted like
     * any other instant here (see moment()).
     */
    public static function time(mixed $value, ?string $format = null): string
    {
        $date = self::moment($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::timeFormat());
    }

    /**
     * A wall-clock time of day, from a `TIME` column — never converted.
     *
     * `time()` is for the time-of-day of an INSTANT, so it shifts into the display timezone. A `TIME`
     * column is not an instant: "this class meets at 09:00" is 09:00 at the institute, and putting it
     * through a timezone would move a morning class to the afternoon for anybody viewing from
     * elsewhere. So this parses the value as a clock face and formats it as one.
     *
     * Pass a format for a machine context — `app_clock($t, 'H:i')` is what an `<input type="time">`
     * requires, whatever the localization setting says a time should look like to a person.
     */
    public static function clock(mixed $value, ?string $format = null): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($format ?? self::timeFormat());
        }

        $raw = trim((string) $value);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $parts) !== 1) {
            return self::EMPTY;
        }

        [$hours, $minutes, $seconds] = [(int) $parts[1], (int) $parts[2], (int) ($parts[3] ?? 0)];

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return self::EMPTY;
        }

        // A fixed date, so only the clock face survives: no timezone, no daylight saving, no drift.
        return CarbonImmutable::create(2000, 1, 1, $hours, $minutes, $seconds, 'UTC')
            ->format($format ?? self::timeFormat());
    }

    /**
     * Date and time together: '12 Sep 2026 03:45 PM'.
     */
    public static function dateTime(mixed $value, ?string $format = null): string
    {
        $date = self::moment($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::dateTimeFormat());
    }

    /**
     * '3 hours ago' — the relative form, in the viewer's timezone.
     */
    /**
     * A value for an `<input type="date">`, in the only format a browser accepts: `Y-m-d`.
     *
     * **This is a wire format, not a display format, and that distinction is the whole point.** Every
     * other renderer here honours `general.date_format`; this one must not, because an input given
     * `12 Sep 2026` silently shows blank and the user loses the value they were editing. It is a named
     * method rather than a `->format('Y-m-d')` at the call site so that the difference is visible, and
     * so `NoHardcodedFormatsTest` can keep catching the mistake it exists to catch.
     *
     * The **display timezone still applies**: the field shows the day the reader is in, which is the
     * day they will type against.
     */
    public static function inputDate(mixed $value): string
    {
        return self::carbon($value)?->format('Y-m-d') ?? '';
    }

    /**
     * A value for an `<input type="datetime-local">`: `Y-m-d\TH:i`, the format HTML requires.
     *
     * Converted into the display timezone first, so the time in the box is the time the reader was
     * just shown on the page beside it. Seconds are dropped because the control does not show them
     * unless `step` asks for them, and a value carrying them is silently rejected by some browsers.
     */
    public static function inputDateTime(mixed $value): string
    {
        return self::moment($value)?->format('Y-m-d\TH:i') ?? '';
    }

    public static function forHumans(mixed $value): string
    {
        $date = self::moment($value);

        return $date === null ? self::EMPTY : $date->diffForHumans();
    }

    /**
     * Parse any date-ish value into the viewer's timezone, or null when there is nothing to show.
     *
     * **Calendar dates are wall-clock dates (D61).** A stored instant is UTC and is converted into
     * the display timezone. A calendar date is not an instant: `2026-09-14` is the 14th for everyone,
     * and reading it as UTC midnight and then converting put it on the 13th for every zone west of
     * UTC. Two shapes are calendar dates, and each comes back as that same date at 00:00 in the
     * target timezone, never shifted:
     *
     *  - a **'Y-m-d' string** (nothing but a date — unambiguous);
     *  - a **DateTimeInterface at exactly 00:00:00.000000** on its own clock — what a `date` /
     *    `immutable_date` cast yields, and what a range boundary built with startOfDay() is. PHP
     *    cannot tell that apart from a `datetime` that happens to sit on midnight, so only the
     *    date renderers read it this way; time(), dateTime() and forHumans() go through moment(),
     *    which converts such a value as the instant it may be.
     *
     * An unparseable value is null rather than an exception: a malformed date in a legacy row must
     * not take a whole screen down.
     */
    public static function carbon(mixed $value, ?string $timezone = null): ?CarbonImmutable
    {
        return self::parse($value, $timezone, midnightIsCalendarDate: true);
    }

    /**
     * Like carbon(), for renderers that show a time of day: a 'Y-m-d' string is still a calendar
     * date (it has no time to convert), but every DateTimeInterface is an instant.
     */
    private static function moment(mixed $value, ?string $timezone = null): ?CarbonImmutable
    {
        return self::parse($value, $timezone, midnightIsCalendarDate: false);
    }

    private static function parse(mixed $value, ?string $timezone, bool $midnightIsCalendarDate): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timezone = $timezone ?? self::displayTimezone();

        if (is_string($value) && preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})\s*$/', $value, $parts) === 1) {
            // A date that does not exist ('2026-02-30') is unreadable, not silently next month.
            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                ? self::calendarDate($parts[1].'-'.$parts[2].'-'.$parts[3], $timezone)
                : null;
        }

        if ($midnightIsCalendarDate && $value instanceof DateTimeInterface && $value->format('H:i:s.u') === '00:00:00.000000') {
            return self::calendarDate($value->format('Y-m-d'), $timezone);
        }

        try {
            $date = match (true) {
                $value instanceof CarbonImmutable => $value,
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
                is_int($value) => CarbonImmutable::createFromTimestamp($value),
                is_string($value) => CarbonImmutable::parse($value),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($date === null) {
            return null;
        }

        try {
            return $date->setTimezone($timezone);
        } catch (Throwable) {
            return $date;
        }
    }

    /**
     * 'Y-m-d' as the start of that calendar day in `$timezone` (the first instant that exists, on a
     * day whose midnight a daylight-saving jump skips). An unusable timezone falls back to UTC rather
     * than moving the date.
     */
    private static function calendarDate(string $date, string $timezone): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        } catch (Throwable) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::FALLBACK_TIMEZONE);
            } catch (Throwable) {
                return null;
            }
        }

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Numbers and money
    |--------------------------------------------------------------------------
    */

    /**
     * A plain number with the configured separators: '1,248' or '1,248.75'.
     *
     * Rounded half away from zero through `Money::round()`, so the rule is the same one money
     * follows and no float arithmetic happens on the way. For an amount of money call `money()`
     * instead — it adds the currency and honours its position.
     */
    public static function number(string|int|float|null $value, int $decimals = 0): string
    {
        $decimals = max(0, $decimals);
        $plain = self::toDecimalString($value, $decimals);

        try {
            $rounded = Money::round($plain, $decimals);
        } catch (Throwable) {
            $rounded = $decimals > 0 ? '0.'.str_repeat('0', $decimals) : '0';
        }

        $negative = str_starts_with($rounded, '-');
        $rounded = ltrim($rounded, '-');

        [$integer, $fraction] = array_pad(explode('.', $rounded, 2), 2, '');

        $formatted = self::group($integer, self::thousandSeparator());

        if ($fraction !== '') {
            $formatted .= self::decimalSeparator().$fraction;
        }

        return ($negative && $formatted !== '0' ? '-' : '').$formatted;
    }

    /**
     * A money amount, currency and all: 'Rs 12,480.00'.
     *
     * Straight through to the canonical money surface. A null amount (an empty decimal column)
     * formats as zero so a view never blows up.
     */
    public static function money(?string $amount, bool $withSymbol = true): string
    {
        return Money::format($amount ?? '0', $withSymbol);
    }

    /**
     * A quantity, with the trailing zeros its column carries but nobody wants to read: '1.5', '12',
     * '1,200.25'.
     *
     * An invoice line quantity is `decimal(15,4)` because a rate may be charged per quarter hour, and
     * "8.0000 hours" on a document a client reads is noise. The separators are the configured ones,
     * like every other number on the screen — a quantity printed with a hard-coded dot is the one
     * number on an invoice that disagrees with the rest of it.
     */
    public static function quantity(string|int|float|null $value, int $maxDecimals = 4): string
    {
        $maxDecimals = max(0, $maxDecimals);
        $formatted = self::number($value, $maxDecimals);

        if ($maxDecimals === 0) {
            return $formatted;
        }

        $decimal = self::decimalSeparator();

        // Guarded on the separator being present: without it, '1,000' would lose its own zeros.
        if ($decimal !== '' && str_contains($formatted, $decimal)) {
            $formatted = rtrim(rtrim($formatted, '0'), $decimal);
        }

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * A percentage, at the `decimal(8,4)` precision rates are stored with, trimmed for display:
     * '12.5%'.
     */
    public static function percentage(string|int|float|null $rate, int $decimals = 2): string
    {
        return self::number($rate, $decimals).'%';
    }

    /*
    |--------------------------------------------------------------------------
    | The localization settings, each with its fallback
    |--------------------------------------------------------------------------
    */

    public static function dateFormat(): string
    {
        return self::setting('localization.date_format', self::FALLBACK_DATE_FORMAT);
    }

    public static function timeFormat(): string
    {
        return self::setting('localization.time_format', self::FALLBACK_TIME_FORMAT);
    }

    public static function dateTimeFormat(): string
    {
        return self::dateFormat().' '.self::timeFormat();
    }

    /**
     * The business timezone: the `localization.timezone` setting, falling back to
     * `config('app.timezone')` and then UTC.
     *
     * This is the timezone the business thinks in — what a date range is built in and what a date
     * is rendered in. It is **never** the timezone timestamps are stored in (D61): storage is UTC,
     * `config('app.timezone')` stays `UTC`, and nothing changes it at runtime. `DateRange`
     * converts between the two.
     */
    public static function timezone(): string
    {
        $timezone = self::setting('localization.timezone', '');

        if ($timezone === '' || ! self::isValidTimezone($timezone)) {
            $timezone = self::configString('app.timezone', self::FALLBACK_TIMEZONE);
        }

        return self::isValidTimezone($timezone) ? $timezone : self::FALLBACK_TIMEZONE;
    }

    /**
     * The timezone dates are rendered in: the signed-in user's own timezone when they set one
     * (phase-01 §1.1 — `users.timezone` "falls back to app timezone"), otherwise the application
     * timezone.
     */
    public static function displayTimezone(): string
    {
        try {
            $user = auth()->user();

            if ($user instanceof User && is_string($user->timezone) && $user->timezone !== '' && self::isValidTimezone($user->timezone)) {
                return $user->timezone;
            }
        } catch (Throwable) {
            // No container, no session, no guard — fall through to the application timezone.
        }

        return self::timezone();
    }

    public static function locale(): string
    {
        $locale = self::setting('localization.locale', '');

        return $locale === '' ? self::configString('app.locale', self::FALLBACK_LOCALE) : $locale;
    }

    /**
     * 'monday' | 'saturday' | 'sunday'.
     */
    public static function weekStart(): string
    {
        $day = strtolower(self::setting('localization.week_start', self::FALLBACK_WEEK_START));

        return array_key_exists($day, self::WEEK_STARTS) ? $day : self::FALLBACK_WEEK_START;
    }

    /**
     * The same setting as a Carbon day-of-week number, for startOfWeek().
     */
    public static function weekStartsOn(): int
    {
        return self::WEEK_STARTS[self::weekStart()];
    }

    /**
     * The grouping character, or '' for no grouping.
     *
     * The registry documents "Leave empty for no grouping", so an **empty stored value** is a real
     * choice and is honoured: a `localization.thousand_separator` row that exists and holds nothing
     * (NULL or '') means no grouping. Only a **missing row** — or unreadable settings (a fresh
     * install) — falls back to ','. The value is not trimmed: a space is a legitimate separator.
     */
    public static function thousandSeparator(): string
    {
        $key = 'localization.thousand_separator';

        try {
            $repository = settings_repo();

            if (! $repository->has($key)) {
                return ',';
            }

            $value = $repository->get($key);
        } catch (Throwable) {
            return ',';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : ',';
    }

    public static function decimalSeparator(): string
    {
        $separator = self::setting('localization.decimal_separator', '.');

        return $separator === '' ? '.' : $separator;
    }

    public static function currencyCode(): string
    {
        return Money::currencyCode();
    }

    public static function currencySymbol(): string
    {
        return Money::symbol();
    }

    /*
    |--------------------------------------------------------------------------
    | Aliases matching the helper spelling the contracts use
    |--------------------------------------------------------------------------
    |
    | Several contracts cite `Format::app_date()` / `Format::money()` while the global helpers are
    | `app_date()` / `money()`. Both spellings resolve to the same code so neither call site is
    | ever wrong.
    |
    */

    public static function app_date(mixed $value, ?string $format = null): string
    {
        return self::date($value, $format);
    }

    public static function app_time(mixed $value, ?string $format = null): string
    {
        return self::time($value, $format);
    }

    public static function app_datetime(mixed $value, ?string $format = null): string
    {
        return self::dateTime($value, $format);
    }

    public static function app_number(string|int|float|null $value, int $decimals = 0): string
    {
        return self::number($value, $decimals);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A setting as a trimmed string, with the fallback whenever it is unreadable or empty.
     */
    private static function setting(string $key, string $fallback): string
    {
        try {
            $value = settings_repo()->get($key);
        } catch (Throwable) {
            return $fallback;
        }

        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }

    private static function configString(string $key, string $fallback): string
    {
        try {
            $value = config($key);
        } catch (Throwable) {
            return $fallback;
        }

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private static function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Any incoming number as a plain decimal string bcmath can read — no scientific notation, no
     * separators, no stray symbols. An unreadable value becomes zero rather than an exception.
     */
    private static function toDecimalString(string|int|float|null $value, int $decimals): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return '0';
            }

            // %F never produces scientific notation; two extra places keep the half-up rounding
            // decision intact before Money::round() makes it.
            return sprintf('%.'.($decimals + 2).'F', $value);
        }

        $clean = str_replace([',', ' ', "\u{00A0}", "\u{202F}", '_'], '', trim($value));
        $clean = ltrim($clean, '+');

        return preg_match('/^-?(\d+)?(\.\d+)?$/', $clean) === 1 && $clean !== '' && $clean !== '-'
            ? $clean
            : '0';
    }

    /**
     * Insert thousand separators into a digit string (no floats, unlike number_format()).
     */
    private static function group(string $digits, string $separator): string
    {
        if ($separator === '' || $digits === '') {
            return $digits;
        }

        return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', $separator, $digits);
    }
}
