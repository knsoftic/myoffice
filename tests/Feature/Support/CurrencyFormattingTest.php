<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * `Money::format()` against the **stored** currency settings (phase-01 §3: "format($amount) using
 * the currency setting").
 *
 * The arithmetic lives in Tests\Unit\Support\MoneyTest, which runs without a container and therefore
 * against the fallback currency. This is the other half: the settings an administrator can actually
 * edit have to reach the formatter. SettingSeeder stores them in the `localization` group
 * (`localization.currency_symbol`, `.currency_position`, `.currency_decimals`,
 * `.thousand_separator`, `.decimal_separator`), so those are the keys asserted here.
 */
final class CurrencyFormattingTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function the_seeded_defaults_format_as_rupees(): void
    {
        // DEVELOPMENT_LOG §9: currency PKR.
        $this->assertSame('PKR', Money::currencyCode());
        $this->assertSame('Rs', Money::symbol());
        $this->assertSame('Rs 1,250.50', Money::format('1250.5'));
        $this->assertSame('1,250.50', Money::format('1250.5', false));
        $this->assertSame('Rs 1,250.50', money('1250.5'));
    }

    #[Test]
    public function changing_the_symbol_setting_changes_what_is_rendered(): void
    {
        $this->setCurrency(['currency_symbol' => '€']);

        $this->assertSame('€', Money::symbol());
        $this->assertSame('€ 1,250.50', Money::format('1250.5'));
    }

    #[Test]
    public function the_symbol_can_be_moved_after_the_amount(): void
    {
        $this->setCurrency(['currency_position' => 'after']);

        $this->assertSame('1,250.50 Rs', Money::format('1250.5'));
        $this->assertSame('-1,250.50 Rs', Money::format('-1250.5'));
    }

    #[Test]
    public function the_separators_are_settings_driven(): void
    {
        $this->setCurrency([
            'thousand_separator' => '.',
            'decimal_separator' => ',',
        ]);

        $this->assertSame('Rs 1.250,50', Money::format('1250.5'));
        $this->assertSame('Rs 1.234.567,89', Money::format('1234567.891'));
    }

    #[Test]
    public function the_decimal_places_are_settings_driven(): void
    {
        $this->setCurrency(['currency_decimals' => 0]);

        $this->assertSame('Rs 1,251', Money::format('1250.5'), 'Zero decimals still round half up.');
        $this->assertSame('Rs 1,250', Money::format('1250.49'));

        // The stored amounts themselves are untouched: only the presentation changed.
        $this->assertSame('1250.50', Money::of('1250.5'));
    }

    #[Test]
    public function an_absurd_decimal_setting_falls_back_to_money_scale(): void
    {
        $this->setCurrency(['currency_decimals' => 99]);

        $this->assertSame('Rs 1,250.50', Money::format('1250.5'));
    }

    #[Test]
    public function a_currency_code_with_no_symbol_derives_one(): void
    {
        $this->setCurrency(['currency_symbol' => '', 'currency' => 'USD']);

        $this->assertSame('USD', Money::currencyCode());
        $this->assertSame('$', Money::symbol());
        $this->assertSame('$ 1,250.50', Money::format('1250.5'));
    }

    #[Test]
    public function an_unknown_currency_code_falls_back_to_the_code_itself(): void
    {
        $this->setCurrency(['currency_symbol' => '', 'currency' => 'kes']);

        $this->assertSame('KES', Money::currencyCode());
        $this->assertSame('KES', Money::symbol());
    }

    /**
     * A finance-group override has to win over the localization default, so a later phase can move
     * the setting without breaking anything.
     */
    #[Test]
    public function a_finance_group_override_takes_precedence(): void
    {
        settings_repo()->set('finance.currency_symbol', '₹');

        $this->assertSame('₹', Money::symbol());
        $this->assertSame('₹ 1,250.50', Money::format('1250.5'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function setCurrency(array $values): void
    {
        foreach ($values as $key => $value) {
            settings_repo()->set('localization.'.$key, $value);
        }
    }
}
