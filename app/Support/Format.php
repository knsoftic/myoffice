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
     * guard the call.
     */
    public static function date(mixed $value, ?string $format = null): string
    {
        $date = self::carbon($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::dateFormat());
    }

    /**
     * A time in the configured format: '03:45 PM' or '15:45'.
     */
    public static function time(mixed $value, ?string $format = null): string
    {
        $date = self::carbon($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::timeFormat());
    }

    /**
     * Date and time together: '12 Sep 2026 03:45 PM'.
     */
    public static function dateTime(mixed $value, ?string $format = null): string
    {
        $date = self::carbon($value);

        return $date === null ? self::EMPTY : $date->format($format ?? self::dateTimeFormat());
    }

    /**
     * '3 hours ago' — the relative form, in the viewer's timezone.
     */
    public static function forHumans(mixed $value): string
    {
        $date = self::carbon($value);

        return $date === null ? self::EMPTY : $date->diffForHumans();
    }

    /**
     * Parse any date-ish value into the viewer's timezone, or null when there is nothing to show.
     *
     * An unparseable value is null rather than an exception: a malformed date in a legacy row must
     * not take a whole screen down.
     */
    public static function carbon(mixed $value, ?string $timezone = null): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timezone = $timezone ?? self::displayTimezone();

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

    public static function thousandSeparator(): string
    {
        return self::setting('localization.thousand_separator', ',');
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
