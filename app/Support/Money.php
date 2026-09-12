<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Throwable;

/**
 * Money arithmetic. bcmath only, scale 2, round half up (half away from zero).
 *
 * Golden rule: money never touches a float. Every parameter and every return value of this class
 * is a string; there is not a single float operation inside it — no `+`, `-`, `*`, `/`, no
 * `round()`, no `number_format()`. Amounts move between `decimal(15,2)` columns and this class as
 * strings end to end.
 *
 *   Money::add('1000.00', '250.50');        // '1250.50'
 *   Money::percentage('1500.00', '12.5');   // '187.50'
 *   Money::format('1250.5');                // 'Rs 1,250.50'
 */
final class Money
{
    /** Money scale: two decimal places, matching decimal(15,2). */
    public const SCALE = 2;

    /** Zero at money scale. */
    public const ZERO = '0.00';

    /** Intermediate precision used before rounding back to SCALE. */
    private const WORKING_SCALE = 10;

    /*
    |--------------------------------------------------------------------------
    | Settings keys consulted for the currency presentation, first hit wins
    |--------------------------------------------------------------------------
    |
    | `localization.*` is where SettingSeeder actually stores the currency rows an administrator
    | edits (group `localization`: currency, currency_symbol, currency_position,
    | currency_decimals, thousand_separator, decimal_separator). The `finance.*` / `company.*` /
    | `general.*` names are kept ahead of it so a later finance-specific override still wins;
    | without the localization names in these lists, changing the currency in the admin UI would
    | have no effect on anything this class formats.
    |
    */

    /** Settings keys consulted for the currency symbol. */
    private const SYMBOL_KEYS = [
        'finance.currency_symbol',
        'company.currency_symbol',
        'general.currency_symbol',
        'localization.currency_symbol',
    ];

    /** Settings keys consulted for the currency code. */
    private const CODE_KEYS = [
        'finance.currency',
        'company.currency',
        'general.currency',
        'localization.currency',
    ];

    private const DECIMALS_KEYS = [
        'finance.currency_decimals',
        'company.currency_decimals',
        'localization.currency_decimals',
    ];

    private const THOUSAND_SEPARATOR_KEYS = [
        'finance.thousand_separator',
        'company.thousand_separator',
        'localization.thousand_separator',
    ];

    private const DECIMAL_SEPARATOR_KEYS = [
        'finance.decimal_separator',
        'company.decimal_separator',
        'localization.decimal_separator',
    ];

    private const POSITION_KEYS = [
        'finance.currency_position',
        'company.currency_position',
        'localization.currency_position',
    ];

    /**
     * Normalise any numeric string to a money value: '1,250.5' => '1250.50'.
     */
    public static function of(string $amount): string
    {
        return self::roundHalfUp(self::parse($amount));
    }

    /**
     * Zero at money scale.
     */
    public static function zero(): string
    {
        return self::ZERO;
    }

    public static function add(string $left, string $right): string
    {
        return self::roundHalfUp(
            bcadd(self::parse($left), self::parse($right), self::WORKING_SCALE)
        );
    }

    public static function sub(string $left, string $right): string
    {
        return self::roundHalfUp(
            bcsub(self::parse($left), self::parse($right), self::WORKING_SCALE)
        );
    }

    public static function mul(string $amount, string $multiplier): string
    {
        return self::roundHalfUp(
            bcmul(self::parse($amount), self::parse($multiplier), self::WORKING_SCALE)
        );
    }

    public static function div(string $amount, string $divisor): string
    {
        $divisor = self::parse($divisor);

        if (bccomp($divisor, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Money::div(): division by zero.');
        }

        return self::roundHalfUp(
            bcdiv(self::parse($amount), $divisor, self::WORKING_SCALE)
        );
    }

    /**
     * `$rate` percent of `$base`: percentage('1500.00', '12.5') === '187.50'.
     */
    public static function percentage(string $base, string $rate): string
    {
        $product = bcmul(self::parse($base), self::parse($rate), self::WORKING_SCALE);

        return self::roundHalfUp(bcdiv($product, '100', self::WORKING_SCALE));
    }

    /**
     * The sum of any number of amounts.
     */
    public static function sum(string ...$amounts): string
    {
        $total = '0';

        foreach ($amounts as $amount) {
            $total = bcadd($total, self::parse($amount), self::WORKING_SCALE);
        }

        return self::roundHalfUp($total);
    }

    /**
     * -1 when left < right, 0 when equal, 1 when left > right — compared at money scale.
     */
    public static function compare(string $left, string $right): int
    {
        return bccomp(self::of($left), self::of($right), self::SCALE);
    }

    public static function equals(string $left, string $right): bool
    {
        return self::compare($left, $right) === 0;
    }

    public static function greaterThan(string $left, string $right): bool
    {
        return self::compare($left, $right) === 1;
    }

    public static function lessThan(string $left, string $right): bool
    {
        return self::compare($left, $right) === -1;
    }

    public static function isZero(string $amount): bool
    {
        return bccomp(self::of($amount), self::ZERO, self::SCALE) === 0;
    }

    public static function isNegative(string $amount): bool
    {
        return bccomp(self::of($amount), self::ZERO, self::SCALE) === -1;
    }

    public static function isPositive(string $amount): bool
    {
        return bccomp(self::of($amount), self::ZERO, self::SCALE) === 1;
    }

    /**
     * Flip the sign. Used for reversing ledger entries.
     */
    public static function negate(string $amount): string
    {
        $value = self::of($amount);

        if (bccomp($value, self::ZERO, self::SCALE) === 0) {
            return self::ZERO;
        }

        return self::roundHalfUp(bcsub('0', $value, self::WORKING_SCALE));
    }

    public static function abs(string $amount): string
    {
        $value = self::of($amount);

        return self::isNegative($value) ? self::negate($value) : $value;
    }

    public static function min(string $first, string ...$rest): string
    {
        $min = self::of($first);

        foreach ($rest as $amount) {
            $candidate = self::of($amount);

            if (bccomp($candidate, $min, self::SCALE) === -1) {
                $min = $candidate;
            }
        }

        return $min;
    }

    public static function max(string $first, string ...$rest): string
    {
        $max = self::of($first);

        foreach ($rest as $amount) {
            $candidate = self::of($amount);

            if (bccomp($candidate, $max, self::SCALE) === 1) {
                $max = $candidate;
            }
        }

        return $max;
    }

    /**
     * Human formatting driven by the currency settings.
     *
     * format('1250.5')        // 'Rs 1,250.50'
     * format('1250.5', false) // '1,250.50'
     */
    public static function format(string $amount, bool $withSymbol = true): string
    {
        $currency = self::currency();

        $value = self::roundHalfUp(self::parse($amount), $currency['decimals']);

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        $parts = explode('.', $value, 2);
        $integer = self::group($parts[0], $currency['thousand_separator']);
        $decimals = $parts[1] ?? '';

        $number = $decimals === ''
            ? $integer
            : $integer.$currency['decimal_separator'].$decimals;

        if ($withSymbol && $currency['symbol'] !== '') {
            $number = $currency['position'] === 'after'
                ? $number.$currency['space'].$currency['symbol']
                : $currency['symbol'].$currency['space'].$number;
        }

        return ($negative ? '-' : '').$number;
    }

    /**
     * The currency symbol in use (settings driven).
     */
    public static function symbol(): string
    {
        return self::currency()['symbol'];
    }

    /**
     * The ISO currency code in use (settings driven).
     */
    public static function currencyCode(): string
    {
        return self::currency()['code'];
    }

    /**
     * Clean and validate a numeric string. Never returns anything bcmath cannot digest.
     */
    private static function parse(string $amount): string
    {
        $value = trim($amount);

        // Thousand separators, ordinary and non-breaking spaces, and a leading plus.
        $value = str_replace([',', ' ', "\u{00A0}", "\u{202F}", '_'], '', $value);
        $value = ltrim($value, '+');

        if ($value === '' || $value === '-') {
            return '0';
        }

        if (str_starts_with($value, '.')) {
            $value = '0'.$value;
        } elseif (str_starts_with($value, '-.')) {
            $value = '-0'.substr($value, 1);
        }

        if (str_ends_with($value, '.')) {
            $value .= '0';
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Money: "%s" is not a well-formed decimal string.', $amount)
            );
        }

        return $value;
    }

    /**
     * Round half away from zero at the given scale, without ever touching a float.
     *
     * bcmath truncates, so half the unit in the last place is added to the magnitude before
     * truncation: 1.005 => 1.01, 1.004 => 1.00, -1.005 => -1.01.
     */
    private static function roundHalfUp(string $value, int $scale = self::SCALE): string
    {
        $negative = str_starts_with($value, '-');
        $magnitude = ltrim($value, '-');

        if ($magnitude === '') {
            $magnitude = '0';
        }

        $half = $scale > 0
            ? '0.'.str_repeat('0', $scale).'5'
            : '0.5';

        $rounded = bcadd($magnitude, $half, $scale);

        if (bccomp($rounded, '0', $scale) === 0) {
            return self::zeroAtScale($scale);
        }

        return ($negative ? '-' : '').$rounded;
    }

    private static function zeroAtScale(int $scale): string
    {
        return $scale > 0 ? '0.'.str_repeat('0', $scale) : '0';
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

    /**
     * Currency presentation, read from settings with safe defaults.
     *
     * @return array{symbol: string, code: string, decimals: int, thousand_separator: string, decimal_separator: string, position: string, space: string}
     */
    private static function currency(): array
    {
        $code = self::settingString(self::CODE_KEYS, 'PKR');
        $symbol = self::settingString(self::SYMBOL_KEYS, '');

        if ($symbol === '') {
            $symbol = match (strtoupper($code)) {
                'PKR' => 'Rs',
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'AED' => 'AED',
                'SAR' => 'SAR',
                'INR' => '₹',
                default => strtoupper($code),
            };
        }

        $decimals = (int) self::settingString(self::DECIMALS_KEYS, (string) self::SCALE);

        if ($decimals < 0 || $decimals > 6) {
            $decimals = self::SCALE;
        }

        $position = strtolower(self::settingString(self::POSITION_KEYS, 'before'));

        return [
            'symbol' => $symbol,
            'code' => strtoupper($code),
            'decimals' => $decimals,
            'thousand_separator' => self::settingString(self::THOUSAND_SEPARATOR_KEYS, ','),
            'decimal_separator' => self::settingString(self::DECIMAL_SEPARATOR_KEYS, '.') ?: '.',
            'position' => $position === 'after' ? 'after' : 'before',
            'space' => ' ',
        ];
    }

    /**
     * First non-empty setting among the candidate keys, else the fallback.
     *
     * @param  array<int, string>  $keys
     */
    private static function settingString(array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            try {
                $value = settings_repo()->get($key);
            } catch (Throwable) {
                // Settings unavailable (no container, no table): use the fallback.
                return $fallback;
            }

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return $fallback;
    }
}
