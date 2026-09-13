<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RemainderPlacement;
use InvalidArgumentException;
use Throwable;

/**
 * The one canonical money surface for the whole system (phase-01 §3, F-4.11 / ND-11).
 *
 * bcmath only, scale 2, round half up (half away from zero). Golden rule: money never touches a
 * float. Every parameter and every return value of this class is a string; there is not a single
 * float operation inside it — no `+`, `-`, `*`, `/`, no `round()`, no `number_format()`. Amounts
 * move between `decimal(15,2)` columns and this class as strings end to end.
 *
 *   Money::add('1000.00', '250.50');        // '1250.50'
 *   Money::percentage('1500.00', '12.5');   // '187.50'
 *   Money::distribute('100.00', 3);         // ['33.34', '33.33', '33.33']
 *   Money::format('1250.5');                // 'Rs 1,250.50'
 *
 * ---------------------------------------------------------------------------------------------
 * The canonical 20 (phase-01 §3 · ND-11) — no later phase adds a second money helper
 * ---------------------------------------------------------------------------------------------
 *
 *   add · sub · mul · div · percentage · percentageOf · compare · isZero · isNegative · abs ·
 *   min · max · sum · round · roundTo · prorate · distribute · toMinor · fromMinor · format
 *
 * `distribute()` is the only function that may split a money value, and its shares always sum
 * back to the total exactly; the remainder rule is `App\Enums\RemainderPlacement`.
 * `allocate()` is its weighted twin (the same guarantee, shares proportional to weights) and
 * routes through the same integer-minor-unit arithmetic, so there is still exactly one splitter.
 *
 * Beyond the canonical list this class also carries the Phase 1 conveniences its own test suite
 * pins down — `of`, `zero`, `equals`, `greaterThan`, `lessThan`, `isPositive`, `negate`, `symbol`,
 * `currencyCode` — plus `allocate` and `standardSymbol` (the code-to-symbol table the formatter
 * and the Localization save share). Nothing else belongs here.
 */
final class Money
{
    /** Money scale: two decimal places, matching decimal(15,2). */
    public const SCALE = 2;

    /** Percentage / rate scale, matching decimal(8,4). */
    public const RATE_SCALE = 4;

    /** Zero at money scale. */
    public const ZERO = '0.00';

    /** Intermediate precision used before rounding back to SCALE. */
    private const WORKING_SCALE = 10;

    /*
    |--------------------------------------------------------------------------
    | Settings keys consulted for the currency presentation
    |--------------------------------------------------------------------------
    |
    | Declared `localization.*` keys only — the rows the Localization screen edits
    | (phase-02 §2 amendment: "a key a view reads must be declared by the registry"). The Phase 1
    | build also consulted undeclared `finance.*` / `company.*` / `general.*` names ahead of them,
    | "so a later override could win": that is exactly the split-truth defect — any such row would
    | silently override what the administrator saves, and no screen could change it back. A later
    | phase that needs a finance-specific currency declares the key in SettingsRegistry first.
    |
    | There is no decimals setting either: every money column is decimal(15,2) and this class works
    | at SCALE, so an amount is always shown to the paisa it is stored with. Printing a rounded
    | figure beside a ledger that sums to the paisa is a presentation that lies
    | (SettingsRegistry: `localization.currency_decimals` is intentionally undeclared).
    |
    */

    /** Settings keys consulted for the currency symbol. */
    private const SYMBOL_KEYS = [
        'localization.currency_symbol',
    ];

    /** Settings keys consulted for the currency code. */
    private const CODE_KEYS = [
        'localization.currency',
    ];

    private const THOUSAND_SEPARATOR_KEYS = [
        'localization.thousand_separator',
    ];

    private const DECIMAL_SEPARATOR_KEYS = [
        'localization.decimal_separator',
    ];

    private const POSITION_KEYS = [
        'localization.currency_position',
    ];

    /**
     * The standard symbol for each currency the Localization screen offers; any other code is
     * shown as the code itself.
     *
     * @var array<string, string>
     */
    private const STANDARD_SYMBOLS = [
        'PKR' => 'Rs',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'AED',
        'SAR' => 'SAR',
        'INR' => '₹',
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
     * What percentage `$part` is of `$whole`: percentageOf('187.50', '1500.00') === '12.5000'.
     *
     * A rate, not an amount — so it comes back at RATE_SCALE (4), matching every `decimal(8,4)`
     * `*_rate` / `*_percentage` column. A zero `$whole` has no answer and is refused rather than
     * reported as 0%: the caller has to decide what "x% of nothing" means (the commission engine
     * skips the release with `no_collectible_denominator`).
     */
    public static function percentageOf(string $part, string $whole, int $scale = self::RATE_SCALE): string
    {
        $whole = self::parse($whole);

        if (bccomp($whole, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Money::percentageOf(): the whole is zero.');
        }

        $scaled = bcmul(self::parse($part), '100', self::WORKING_SCALE);

        return self::roundHalfUp(bcdiv($scaled, $whole, self::WORKING_SCALE), max(0, $scale));
    }

    /**
     * The sum of any number of amounts.
     *
     * Takes both shapes the contracts use — variadic strings and one array (phase-05 §13.2 asks
     * for `sum(array): string`) — and any mix of the two:
     *
     *   Money::sum('1.00', '2.00');          // '3.00'
     *   Money::sum(['1.00', '2.00']);        // '3.00'
     *   Money::sum($lines->all());           // '…'
     *
     * @param  string|array<array-key, string>  ...$amounts
     */
    public static function sum(string|array ...$amounts): string
    {
        $total = '0';

        foreach ($amounts as $amount) {
            foreach (is_array($amount) ? $amount : [$amount] as $value) {
                $total = bcadd($total, self::parse((string) $value), self::WORKING_SCALE);
            }
        }

        return self::roundHalfUp($total);
    }

    /**
     * Round an amount half away from zero at `$scale` decimals (default money scale).
     *
     * The public face of the rounding rule every other method uses, so nothing outside this class
     * ever reaches for PHP's `round()` on a money value.
     */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Money::round(): the scale cannot be negative.');
        }

        return self::roundHalfUp(self::parse($value), $scale);
    }

    /**
     * Round to the nearest multiple of `$nearest` whole currency units, half away from zero.
     *
     *   roundTo('1234.56', 10)   // '1230.00'
     *   roundTo('1235.00', 10)   // '1240.00'
     *   roundTo('-1235.00', 10)  // '-1240.00'
     *   roundTo('12.34', 1)      // '12.00'
     *
     * Used where a business rounds a printed figure (a fee slip to the nearest 10, a payout to
     * the nearest 100). It never changes a stored ledger amount.
     */
    public static function roundTo(string $amount, int $nearest): string
    {
        if ($nearest < 1) {
            throw new InvalidArgumentException('Money::roundTo(): the step must be a positive whole number.');
        }

        $value = self::of($amount);
        $multiples = self::roundHalfUp(bcdiv($value, (string) $nearest, self::WORKING_SCALE), 0);

        return self::roundHalfUp(bcmul($multiples, (string) $nearest, self::WORKING_SCALE));
    }

    /**
     * `$part` of `$total`, applied to `$amount`: prorate('2000.00', '10000.00', '30000.00') === '666.67'.
     *
     * One rounded multiplication-then-division, so a pro-rata share never drifts by being
     * computed in two steps. A zero `$total` is refused — there is no share of nothing.
     */
    public static function prorate(string $amount, string $part, string $total): string
    {
        $total = self::parse($total);

        if (bccomp($total, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Money::prorate(): the total is zero.');
        }

        $product = bcmul(self::parse($amount), self::parse($part), self::WORKING_SCALE);

        return self::roundHalfUp(bcdiv($product, $total, self::WORKING_SCALE));
    }

    /**
     * Split an amount into `$parts` equal shares whose sum is EXACTLY the amount.
     *
     *   distribute('100.00', 3)                               // ['33.34', '33.33', '33.33']
     *   distribute('100.00', 3, RemainderPlacement::Last)      // ['33.33', '33.33', '33.34']
     *   distribute('-100.00', 3)                              // ['-33.34', '-33.33', '-33.33']
     *
     * The only function in the system that may split a money value. The arithmetic happens in
     * integer minor units, so the shares are exact by construction rather than by a final fix-up:
     * every share is the truncated quotient and the leftover paisa are handed out one at a time,
     * where `App\Enums\RemainderPlacement` says. A negative amount splits with the same rule on
     * its magnitude, so a reversal mirrors the split it reverses, share for share.
     *
     * @return list<string>
     */
    public static function distribute(
        string $amount,
        int $parts,
        RemainderPlacement $placement = RemainderPlacement::First
    ): array {
        if ($parts < 1) {
            throw new InvalidArgumentException('Money::distribute(): a split needs at least one part.');
        }

        $minor = self::toMinor($amount);
        $base = bcdiv($minor, (string) $parts, 0);
        $shares = array_fill(0, $parts, $base);

        $leftover = bcsub($minor, bcmul($base, (string) $parts, 0), 0);

        // Every share is equal here, so "largest" is the first one (RemainderPlacement::Largest).
        $order = match ($placement) {
            RemainderPlacement::Last => array_reverse(range(0, $parts - 1)),
            default => range(0, $parts - 1),
        };

        foreach (self::spread($leftover, $order) as $index => $unit) {
            $shares[$index] = bcadd($shares[$index], $unit, 0);
        }

        return array_map(static fn (string $share): string => self::fromMinor($share), $shares);
    }

    /**
     * Split an amount in proportion to `$weights`, summing EXACTLY to the amount.
     *
     *   allocate('100.00', ['1', '1', '2'])        // ['25.00', '25.00', '50.00']
     *   allocate('100.00', ['1', '2', '3'])        // ['16.67', '33.33', '50.00']
     *   allocate('100.00', ['1', '1', '1'])        // ['33.34', '33.33', '33.33']
     *
     * The weighted twin of distribute(): same integer minor units, same exactness guarantee. The
     * default remainder rule is `Largest` — the largest-remainder method, which is the standard
     * apportionment and keeps every share within one paisa of its exact proportional value; ties
     * fall to the bigger share and then to the earlier key. Keys are preserved, so a weights map
     * keyed by installment id comes back keyed the same way.
     *
     * Weights may not be negative and must not all be zero.
     *
     * @param  array<array-key, string|int>  $weights
     * @return array<array-key, string>
     */
    public static function allocate(
        string $amount,
        array $weights,
        RemainderPlacement $placement = RemainderPlacement::Largest
    ): array {
        if ($weights === []) {
            throw new InvalidArgumentException('Money::allocate(): there is nothing to allocate to.');
        }

        $keys = array_keys($weights);
        $parsed = [];
        $totalWeight = '0';

        foreach ($keys as $key) {
            $weight = self::parse((string) $weights[$key]);

            if (bccomp($weight, '0', self::WORKING_SCALE) === -1) {
                throw new InvalidArgumentException('Money::allocate(): a weight cannot be negative.');
            }

            $parsed[$key] = $weight;
            $totalWeight = bcadd($totalWeight, $weight, self::WORKING_SCALE);
        }

        if (bccomp($totalWeight, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Money::allocate(): the weights sum to zero.');
        }

        $minor = self::toMinor($amount);
        $shares = [];
        $remainders = [];
        $allocated = '0';

        foreach ($keys as $key) {
            // share = trunc(minor * weight / totalWeight); remainder = the discarded fraction.
            $exact = bcmul($minor, $parsed[$key], self::WORKING_SCALE);
            $share = bcdiv($exact, $totalWeight, 0);
            $shares[$key] = $share;
            $remainders[$key] = self::absolute(
                bcsub($exact, bcmul($share, $totalWeight, self::WORKING_SCALE), self::WORKING_SCALE)
            );
            $allocated = bcadd($allocated, $share, 0);
        }

        $leftover = bcsub($minor, $allocated, 0);

        $order = match ($placement) {
            RemainderPlacement::First => $keys,
            RemainderPlacement::Last => array_reverse($keys),
            RemainderPlacement::Largest => self::byLargestRemainder($keys, $remainders, $shares),
        };

        foreach (self::spread($leftover, $order) as $key => $unit) {
            $shares[$key] = bcadd($shares[$key], $unit, 0);
        }

        return array_map(static fn (string $share): string => self::fromMinor($share), $shares);
    }

    /**
     * An amount as whole minor units (paisa, cents) — a digit string, never a float or an int
     * that could overflow on a 32-bit build.
     *
     *   toMinor('1250.50')   // '125050'
     *   toMinor('-0.07')     // '-7'
     */
    public static function toMinor(string $amount): string
    {
        $value = self::of($amount);

        return bcmul($value, '100', 0);
    }

    /**
     * Minor units back to a money string: fromMinor('125050') === '1250.50'.
     */
    public static function fromMinor(string|int $minor): string
    {
        $value = self::parse((string) $minor);

        if (str_contains($value, '.')) {
            throw new InvalidArgumentException(
                sprintf('Money::fromMinor(): "%s" is not a whole number of minor units.', $minor)
            );
        }

        return self::roundHalfUp(bcdiv($value, '100', self::WORKING_SCALE));
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
     * The standard symbol for an ISO code: standardSymbol('USD') === '$'; an unlisted code is
     * shown as the code itself.
     */
    public static function standardSymbol(string $code): string
    {
        $code = strtoupper(trim($code));

        return self::STANDARD_SYMBOLS[$code] ?? $code;
    }

    /**
     * Hand `$leftover` minor units out one at a time, following `$order`, and return
     * [key => '1' | '-1'] for the keys that received one.
     *
     * `|$leftover|` is always smaller than the number of shares (it is what truncating a quotient
     * leaves behind), so one pass over `$order` is always enough; the guard is belt and braces.
     *
     * @param  array<int, array-key>  $order
     * @return array<array-key, string>
     */
    private static function spread(string $leftover, array $order): array
    {
        $units = [];

        if (bccomp($leftover, '0', 0) === 0 || $order === []) {
            return $units;
        }

        $unit = bccomp($leftover, '0', 0) === -1 ? '-1' : '1';
        $remaining = self::absolute($leftover, 0);
        $position = 0;

        while (bccomp($remaining, '0', 0) === 1) {
            $key = $order[$position % count($order)];
            $units[$key] = bcadd($units[$key] ?? '0', $unit, 0);
            $remaining = bcsub($remaining, '1', 0);
            $position++;
        }

        return $units;
    }

    /**
     * Keys ordered by the largest discarded remainder first; ties go to the bigger share, then to
     * the earlier key — so an allocation is reproducible to the paisa.
     *
     * @param  array<int, array-key>  $keys
     * @param  array<array-key, string>  $remainders
     * @param  array<array-key, string>  $shares
     * @return array<int, array-key>
     */
    private static function byLargestRemainder(array $keys, array $remainders, array $shares): array
    {
        $positions = array_flip(array_map(static fn (mixed $key): string => (string) $key, $keys));

        usort($keys, static function (mixed $a, mixed $b) use ($remainders, $shares, $positions): int {
            $byRemainder = bccomp($remainders[$b], $remainders[$a], self::WORKING_SCALE);

            if ($byRemainder !== 0) {
                return $byRemainder;
            }

            $byShare = bccomp(self::absolute($shares[$b], 0), self::absolute($shares[$a], 0), 0);

            if ($byShare !== 0) {
                return $byShare;
            }

            return $positions[(string) $a] <=> $positions[(string) $b];
        });

        return $keys;
    }

    /**
     * Magnitude of a bcmath value at the given scale, without touching abs().
     */
    private static function absolute(string $value, int $scale = self::WORKING_SCALE): string
    {
        if (bccomp($value, '0', $scale) === -1) {
            return bcsub('0', $value, $scale);
        }

        return $value;
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
            $symbol = self::standardSymbol($code);
        }

        $position = strtolower(self::settingString(self::POSITION_KEYS, 'before'));

        return [
            'symbol' => $symbol,
            'code' => strtoupper($code),
            'decimals' => self::SCALE,
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
