<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Global helpers
|--------------------------------------------------------------------------
|
| Autoloaded through composer's "autoload.files". Thin wrappers only: every line of logic
| lives in the App\Support classes these functions delegate to.
|
*/

use App\Models\User;
use App\Support\Format;
use App\Support\Money;
use App\Support\SettingsRepository;
use App\Support\Sidebar;
use App\Support\SiteSettings;

if (! function_exists('settings_repo')) {
    /**
     * The settings singleton.
     */
    function settings_repo(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }
}

if (! function_exists('setting')) {
    /**
     * Read a setting by its dotted "group.key" name.
     *
     *   setting('company.name', 'My Office');
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return settings_repo()->get($key, $default);
    }
}

if (! function_exists('site_setting')) {
    /**
     * The ONLY way a public view reads a setting (phase-03 §5.3, INV-10). Throws
     * App\Support\Exceptions\NonPublicSettingException for any key whose registry definition is not
     * `public => true`, so a secret can never be printed into a public page by mistake.
     *
     *   site_setting('company.name', '');
     */
    function site_setting(string $key, mixed $default = null): mixed
    {
        return app(SiteSettings::class)->get($key, $default);
    }
}

if (! function_exists('money')) {
    /**
     * Format a money string for display using the currency settings.
     *
     *   money('1250.5')        // 'Rs 1,250.50'
     *   money('1250.5', false) // '1,250.50'
     *
     * A null amount (an empty decimal column) formats as zero so views never blow up.
     */
    function money(?string $amount, bool $withSymbol = true): string
    {
        return Money::format($amount ?? '0', $withSymbol);
    }
}

if (! function_exists('app_date')) {
    /**
     * A date in the configured format: app_date($invoice->issued_on) // '12 Sep 2026'.
     *
     * Takes a Carbon instance, a model datetime, a date string or a timestamp; renders the empty
     * string for null. Pass `$format` only when a document genuinely needs a fixed format.
     */
    function app_date(mixed $value, ?string $format = null): string
    {
        return Format::date($value, $format);
    }
}

if (! function_exists('app_time')) {
    /**
     * A time in the configured format: app_time($session->starts_at) // '03:45 PM'.
     */
    function app_time(mixed $value, ?string $format = null): string
    {
        return Format::time($value, $format);
    }
}

if (! function_exists('app_clock')) {
    /**
     * A wall-clock time from a `TIME` column — a class at 09:00 is 09:00, wherever it is read from.
     */
    function app_clock(mixed $value, ?string $format = null): string
    {
        return Format::clock($value, $format);
    }
}

if (! function_exists('app_datetime')) {
    /**
     * Date and time together: app_datetime($activity->created_at) // '12 Sep 2026 03:45 PM'.
     */
    function app_datetime(mixed $value, ?string $format = null): string
    {
        return Format::dateTime($value, $format);
    }
}

if (! function_exists('app_input_date')) {
    /**
     * A date for an `<input type="date">` — the browser's `Y-m-d`, not the reader's format.
     *
     * Deliberately outside the display-format family: an input handed a localized date renders blank
     * and loses what the user was editing. See {@see Format::inputDate()}.
     */
    function app_input_date(mixed $value): string
    {
        return Format::inputDate($value);
    }
}

if (! function_exists('app_input_datetime')) {
    /**
     * A moment for an `<input type="datetime-local">`, in the display timezone.
     */
    function app_input_datetime(mixed $value): string
    {
        return Format::inputDateTime($value);
    }
}

if (! function_exists('app_ordinal')) {
    /**
     * A rank as it is read aloud: app_ordinal(2) // '2nd', app_ordinal(13) // '13th'.
     *
     * Null or anything below 1 gives an em dash — a student with no position has none, which is not
     * the same as coming last.
     */
    function app_ordinal(?int $value): string
    {
        return Format::ordinal($value);
    }
}

if (! function_exists('app_number')) {
    /**
     * A plain number with the configured separators: app_number(1248) // '1,248'.
     *
     * For an amount of money use money() — it adds the currency and honours its position.
     */
    function app_number(string|int|float|null $value, int $decimals = 0): string
    {
        return Format::number($value, $decimals);
    }
}

if (! function_exists('app_quantity')) {
    /**
     * A quantity without its trailing zeros: app_quantity('8.0000') // '8'.
     *
     * For an amount of money use money(); for a count or a plain figure use app_number().
     */
    function app_quantity(string|int|float|null $value, int $maxDecimals = 4): string
    {
        return Format::quantity($value, $maxDecimals);
    }
}

if (! function_exists('per_page')) {
    /**
     * Rows per page for an admin list screen: `appearance.table_page_size`, clamped to 10..100.
     *
     *   ->paginate(per_page())
     *
     * The clamp is the guard, not the form rule: a value written before the rule existed, or by a
     * raw SQL edit, must never ask the database for 5,000 rows or render a one-row page. A value
     * that is not a number at all falls back to 15, the registry default.
     */
    function per_page(): int
    {
        $value = setting('appearance.table_page_size', 15);
        $size = is_numeric($value) ? (int) $value : 15;

        return max(10, min(100, $size));
    }
}

if (! function_exists('sidebar_items')) {
    /**
     * The navigation tree for a user (defaults to the authenticated user), already filtered
     * by module, route and permission.
     *
     * @return array<int, array<string, mixed>>
     */
    function sidebar_items(?User $user = null): array
    {
        if ($user === null) {
            $authenticated = auth()->user();
            $user = $authenticated instanceof User ? $authenticated : null;
        }

        return Sidebar::forUser($user);
    }
}
