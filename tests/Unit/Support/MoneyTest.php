<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * App\Support\Money — bcmath only, scale 2, round half away from zero (CLAUDE.md rule 4:
 * "money never touches a float").
 *
 * A pure unit test: no application, no database. That is deliberate twice over — it proves the
 * class really has no dependencies beyond bcmath, and it pins `format()` to the documented
 * fallback currency (PKR / "Rs" / `,` / `.` / symbol first) instead of to whatever a seeded
 * settings row happens to say.
 */
final class MoneyTest extends TestCase
{
    /**
     * Money::format() reads the currency settings through the container. Clearing the container
     * makes the settings unreachable, which is the documented "fall back to the defaults" path —
     * and keeps these assertions independent of the order the suite happens to run in.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Normalisation
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalisationProvider(): array
    {
        return [
            'already at scale' => ['1250.50', '1250.50'],
            'one decimal' => ['1250.5', '1250.50'],
            'no decimals' => ['1250', '1250.00'],
            'thousand separators' => ['1,250.5', '1250.50'],
            'underscores and spaces' => ['1_250 000.5', '1250000.50'],
            'leading dot' => ['.5', '0.50'],
            'trailing dot' => ['5.', '5.00'],
            'leading plus' => ['+5.5', '5.50'],
            'surrounding space' => ['  12.34  ', '12.34'],
            'empty string' => ['', '0.00'],
            'lonely minus' => ['-', '0.00'],
            'negative' => ['-7.5', '-7.50'],
            'rounds half up' => ['1.005', '1.01'],
            'rounds half up away from zero' => ['-1.005', '-1.01'],
            'rounds down below half' => ['1.004', '1.00'],
            'truncation would lose a rupee' => ['0.999', '1.00'],
        ];
    }

    #[Test]
    #[DataProvider('normalisationProvider')]
    public function of_normalises_any_numeric_string(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::of($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedProvider(): array
    {
        return [
            'letters' => ['abc'],
            'mixed' => ['12abc'],
            'two dots' => ['1.2.3'],
            'currency symbol' => ['Rs 100'],
            'percent' => ['10%'],
            'scientific notation' => ['1e5'],
            'two minus signs' => ['--5'],
            'trailing minus' => ['5-'],
        ];
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function a_malformed_amount_is_refused_rather_than_silently_zeroed(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of($input);
    }

    #[Test]
    public function zero_is_the_scaled_zero(): void
    {
        $this->assertSame('0.00', Money::zero());
        $this->assertSame('0.00', Money::ZERO);
        $this->assertSame(2, Money::SCALE);
    }

    /*
    |--------------------------------------------------------------------------
    | Arithmetic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function addition_is_exact_where_floats_are_not(): void
    {
        // The canonical float failure: 0.1 + 0.2 !== 0.3.
        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('1250.50', Money::add('1000.00', '250.50'));
        $this->assertSame('0.00', Money::add('-5.00', '5.00'));
        $this->assertSame('-2.50', Money::add('-5.00', '2.50'));
    }

    #[Test]
    public function subtraction_handles_negatives(): void
    {
        $this->assertSame('749.50', Money::sub('1000.00', '250.50'));
        $this->assertSame('-50.00', Money::sub('100.00', '150.00'));
        $this->assertSame('0.00', Money::sub('100.00', '100.00'));
        $this->assertSame('-150.00', Money::sub('-100.00', '50.00'));
    }

    #[Test]
    public function multiplication_rounds_at_money_scale(): void
    {
        $this->assertSame('0.02', Money::mul('0.1', '0.2'));
        $this->assertSame('150.00', Money::mul('100.00', '1.5'));
        $this->assertSame('-150.00', Money::mul('100.00', '-1.5'));
        $this->assertSame('0.00', Money::mul('100.00', '0'));

        // 33.335 rounds half away from zero, not "to even".
        $this->assertSame('33.34', Money::mul('6.667', '5'));
    }

    #[Test]
    public function division_rounds_at_money_scale(): void
    {
        $this->assertSame('3.33', Money::div('10.00', '3'));
        $this->assertSame('333.33', Money::div('1000.00', '3'));
        $this->assertSame('-3.33', Money::div('-10.00', '3'));
        $this->assertSame('2.50', Money::div('10.00', '4'));
    }

    #[Test]
    public function dividing_by_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('division by zero');

        Money::div('100.00', '0');
    }

    #[Test]
    public function amounts_beyond_float_precision_stay_exact(): void
    {
        // decimal(15,2) goes to 9 999 999 999 999.99; a float cannot represent the carry here.
        $this->assertSame('10000000000000.00', Money::add('9999999999999.99', '0.01'));
        $this->assertSame('9999999999999.98', Money::sub('9999999999999.99', '0.01'));
        $this->assertSame('9999999999999.99', Money::of('9999999999999.99'));
    }

    #[Test]
    public function sum_adds_any_number_of_amounts(): void
    {
        $this->assertSame('0.00', Money::sum());
        $this->assertSame('6.00', Money::sum('1.00', '2.00', '3.00'));
        $this->assertSame('0.60', Money::sum('0.1', '0.2', '0.3'));
        $this->assertSame('-1.00', Money::sum('1.00', '-2.00'));
    }

    /*
    |--------------------------------------------------------------------------
    | Percentages — the commission engine's only arithmetic
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function percentageProvider(): array
    {
        return [
            'documented example' => ['1500.00', '12.5', '187.50'],
            'whole percent' => ['1000.00', '10', '100.00'],
            'fractional rate' => ['1000.00', '7.5', '75.00'],
            'four-decimal rate' => ['1000.00', '12.3456', '123.46'],
            'zero base' => ['0.00', '12.5', '0.00'],
            'zero rate' => ['1500.00', '0', '0.00'],
            'hundred percent' => ['1500.00', '100', '1500.00'],
            'over hundred percent' => ['1500.00', '150', '2250.00'],
            'negative base (a reversal)' => ['-1500.00', '12.5', '-187.50'],
            'negative rate' => ['1500.00', '-12.5', '-187.50'],
            'rounds half up' => ['1.00', '50.5', '0.51'],
            'needs the working scale' => ['333.33', '33.33', '111.10'],
        ];
    }

    #[Test]
    #[DataProvider('percentageProvider')]
    public function percentage_takes_a_rate_of_a_base(string $base, string $rate, string $expected): void
    {
        $this->assertSame($expected, Money::percentage($base, $rate));
    }

    /*
    |--------------------------------------------------------------------------
    | Comparison
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function comparison_happens_at_money_scale(): void
    {
        $this->assertSame(0, Money::compare('1.00', '1.00'));
        $this->assertSame(1, Money::compare('1.01', '1.00'));
        $this->assertSame(-1, Money::compare('0.99', '1.00'));

        // Anything below the second decimal is not money, so it cannot make two amounts differ.
        $this->assertSame(0, Money::compare('1.001', '1.00'));
        $this->assertTrue(Money::equals('1.001', '1.00'));
    }

    #[Test]
    public function the_comparison_helpers_agree_with_compare(): void
    {
        $this->assertTrue(Money::greaterThan('2.00', '1.99'));
        $this->assertFalse(Money::greaterThan('1.99', '2.00'));
        $this->assertTrue(Money::lessThan('1.99', '2.00'));
        $this->assertFalse(Money::lessThan('2.00', '2.00'));
        $this->assertTrue(Money::equals('-0.00', '0.00'));
    }

    #[Test]
    public function the_sign_helpers_read_the_rounded_value(): void
    {
        $this->assertTrue(Money::isZero('0'));
        $this->assertTrue(Money::isZero('0.004'), 'Sub-paisa amounts round to zero.');
        $this->assertFalse(Money::isZero('0.005'), 'Half a paisa rounds up to one.');

        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertFalse(Money::isNegative('0.00'));
        $this->assertFalse(Money::isNegative('-0.004'));

        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertFalse(Money::isPositive('0.00'));
    }

    #[Test]
    public function negate_flips_the_sign_without_producing_minus_zero(): void
    {
        $this->assertSame('-50.00', Money::negate('50.00'));
        $this->assertSame('50.00', Money::negate('-50.00'));
        $this->assertSame('0.00', Money::negate('0.00'), 'A reversing entry of zero is still zero.');
        $this->assertSame('0.00', Money::negate('-0.00'));
    }

    #[Test]
    public function abs_drops_the_sign(): void
    {
        $this->assertSame('50.00', Money::abs('-50.00'));
        $this->assertSame('50.00', Money::abs('50.00'));
        $this->assertSame('0.00', Money::abs('0.00'));
    }

    #[Test]
    public function min_and_max_pick_at_money_scale(): void
    {
        $this->assertSame('1.00', Money::min('1.00', '2.00', '3.00'));
        $this->assertSame('-3.00', Money::min('1.00', '-3.00', '2.00'));
        $this->assertSame('3.00', Money::max('1.00', '3.00', '2.00'));
        $this->assertSame('1.00', Money::max('1.00'));
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function format_uses_the_fallback_currency_when_no_setting_is_available(): void
    {
        $this->assertSame('Rs 1,250.50', Money::format('1250.5'));
        $this->assertSame('1,250.50', Money::format('1250.5', false));
        $this->assertSame('Rs 0.00', Money::format('0'));
        $this->assertSame('Rs 100.00', Money::format('100'));
        $this->assertSame('PKR', Money::currencyCode());
        $this->assertSame('Rs', Money::symbol());
    }

    #[Test]
    public function format_groups_thousands_without_number_format(): void
    {
        $this->assertSame('Rs 1,234,567.89', Money::format('1234567.891'));
        $this->assertSame('Rs 999.99', Money::format('999.99'));
        $this->assertSame('Rs 1,000.00', Money::format('999.995'));
        $this->assertSame('Rs 9,999,999,999,999.99', Money::format('9999999999999.99'));
    }

    #[Test]
    public function format_keeps_the_minus_in_front_of_everything(): void
    {
        $this->assertSame('-Rs 1,250.50', Money::format('-1250.5'));
        $this->assertSame('-1,250.50', Money::format('-1250.5', false));
        $this->assertSame('Rs 0.00', Money::format('-0.001'), 'A rounded-away negative is not "-0.00".');
    }

    /*
    |--------------------------------------------------------------------------
    | The golden rule
    |--------------------------------------------------------------------------
    */

    /**
     * Every value in and out of this class is a string. If any of these returned a float, a
     * rounding error could reach a decimal(15,2) column.
     */
    #[Test]
    public function no_operation_ever_returns_a_float(): void
    {
        $returns = [
            'of' => Money::of('1.005'),
            'zero' => Money::zero(),
            'add' => Money::add('1', '2'),
            'sub' => Money::sub('1', '2'),
            'mul' => Money::mul('1', '2'),
            'div' => Money::div('1', '2'),
            'percentage' => Money::percentage('1', '2'),
            'sum' => Money::sum('1', '2'),
            'negate' => Money::negate('1'),
            'abs' => Money::abs('-1'),
            'min' => Money::min('1', '2'),
            'max' => Money::max('1', '2'),
            'format' => Money::format('1'),
            'symbol' => Money::symbol(),
            'currencyCode' => Money::currencyCode(),
        ];

        foreach ($returns as $method => $value) {
            $this->assertIsString($value, sprintf('Money::%s() must return a string.', $method));
            $this->assertIsNotFloat($value);
        }

        $this->assertIsInt(Money::compare('1', '2'));

        foreach (['isZero', 'isNegative', 'isPositive'] as $predicate) {
            $this->assertIsBool(Money::$predicate('1.00'));
        }
    }

    /**
     * Two decimal places, always — a bare integer string out of the database must not come back
     * as one.
     */
    #[Test]
    public function every_result_carries_exactly_two_decimals(): void
    {
        $values = [
            Money::of('5'),
            Money::add('5', '5'),
            Money::sub('5', '5'),
            Money::mul('5', '5'),
            Money::div('5', '5'),
            Money::percentage('5', '5'),
            Money::sum('5'),
            Money::negate('5'),
            Money::abs('5'),
            Money::min('5'),
            Money::max('5'),
        ];

        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $value);
        }
    }

    /**
     * bcmath truncates; the class adds half a unit in the last place first. This is the property
     * that keeps a commission from being a paisa short, so it gets its own exhaustive check.
     */
    #[Test]
    public function rounding_is_half_away_from_zero_at_every_boundary(): void
    {
        $cases = [
            '0.005' => '0.01',
            '0.0049' => '0.00',
            '0.015' => '0.02',
            '0.025' => '0.03',
            '2.675' => '2.68',
            '-0.005' => '-0.01',
            '-2.675' => '-2.68',
            '-0.0049' => '0.00',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Money::of((string) $input), sprintf('of(%s)', $input));
        }
    }
}
