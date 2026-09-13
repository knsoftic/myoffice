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
 * (`localization.currency`, `.currency_symbol`, `.currency_position`, `.thousand_separator`,
 * `.decimal_separator`), so those are the keys asserted here — and only those: an undeclared row
 * never reaches the formatter.
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

    /**
     * Updated in the Phase 2 finishing pass. This used to assert that a `currency_decimals` row
     * reshaped every amount. That key is not declared by SettingsRegistry (no screen can edit it),
     * and phase-02 §2's amendment makes "a key a view reads must be declared" binding — an
     * undeclared row that silently reshapes every amount is the split-truth defect. Money columns
     * are decimal(15,2), so an amount is always shown at money scale.
     */
    #[Test]
    public function an_undeclared_decimals_row_never_changes_the_money_scale(): void
    {
        $this->setCurrency(['currency_decimals' => 0]);

        $this->assertSame('Rs 1,250.50', Money::format('1250.5'));
        $this->assertSame('Rs 1,250.49', Money::format('1250.49'));
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
     * Updated in the Phase 2 finishing pass. This used to assert that an undeclared
     * `finance.currency_symbol` row won over the Localization screen's value. That is the
     * split-truth defect phase-02 §2's amendment forbids: a row no screen can edit silently
     * overriding what the administrator saved (SettingsSplitTruthRegressionTest). A later phase
     * that needs its own currency declares the key in SettingsRegistry first.
     */
    #[Test]
    public function an_undeclared_finance_group_row_never_overrides_the_localization_screen(): void
    {
        settings_repo()->set('finance.currency_symbol', '₹');
        settings_repo()->set('company.currency', 'INR');

        $this->assertSame('Rs', Money::symbol());
        $this->assertSame('PKR', Money::currencyCode());
        $this->assertSame('Rs 1,250.50', Money::format('1250.5'));
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
