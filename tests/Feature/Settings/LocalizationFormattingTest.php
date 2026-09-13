<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\Format;
use App\Support\Money;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Formatting": changing `currency`, `currency_position` and `date_format` changes
 * `money()` and `app_date()` output everywhere.
 *
 * Each change is made the way an administrator makes it — on the Localization screen — and then
 * observed twice: through the helpers directly, and in a page that renders through them.
 */
final class LocalizationFormattingTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const AMOUNT = '1234567.891';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    #[Test]
    public function the_seeded_localization_formats_as_documented(): void
    {
        $this->assertSame('Rs 1,234,567.89', money(self::AMOUNT));

        // D61: a bare datetime string is a stored (UTC) moment, rendered in the display timezone —
        // 09:05 UTC is 14:05 in Asia/Karachi. (This input read '14:05:00' while the build still
        // switched the storage timezone to Karachi at boot; the expected output is unchanged.)
        $this->assertSame('13 Sep 2026', app_date('2026-09-13 09:05:00'));
        $this->assertSame('02:05 PM', app_time('2026-09-13 09:05:00'));
    }

    #[Test]
    public function moving_the_currency_symbol_after_the_amount_changes_money(): void
    {
        $this->saveLocalization(['currency_position' => 'after']);

        $this->assertSame('1,234,567.89 Rs', money(self::AMOUNT));
        $this->assertSame('-1,234,567.89 Rs', money('-'.self::AMOUNT));
        $this->assertSame('1,234,567.89', money(self::AMOUNT, false));
    }

    #[Test]
    public function changing_the_currency_symbol_changes_money(): void
    {
        $this->saveLocalization(['currency' => 'USD', 'currency_symbol' => '$']);

        $this->assertSame('USD', Money::currencyCode());
        $this->assertSame('$ 1,234,567.89', money(self::AMOUNT));
    }

    /**
     * The Localization screen offers a currency select. An administrator who switches it from PKR
     * to USD — and does nothing else — expects every amount to stop saying "Rs".
     */
    #[Test]
    public function changing_the_currency_alone_changes_money(): void
    {
        $before = money(self::AMOUNT);

        $this->saveLocalization(['currency' => 'USD']);

        $this->assertSame('USD', $this->freshSetting('localization.currency'), 'The currency itself was saved.');

        $this->assertNotSame(
            $before,
            money(self::AMOUNT),
            'DESIGN DEFECT: localization.currency was changed from PKR to USD on the settings screen, yet money() '
            .'still renders "'.$before.'". Money::format() prints only localization.currency_symbol, which the '
            .'registry makes `required` with the default "Rs", so the code-to-symbol fallback in Money::currency() '
            .'can never run and the currency select changes nothing any user sees. Needs a decision: derive the '
            .'symbol from the currency unless one is explicitly overridden (nullable currency_symbol), or have '
            .'the screen update both together.'
        );
    }

    #[Test]
    public function the_separators_restyle_every_amount_and_number(): void
    {
        $this->saveLocalization(['thousand_separator' => '.', 'decimal_separator' => ',']);

        $this->assertSame('Rs 1.234.567,89', money(self::AMOUNT));
        $this->assertSame('1.248', app_number(1248));
        $this->assertSame('12,50%', Format::percentage('12.5'));
    }

    #[Test]
    public function changing_the_date_format_changes_app_date_and_app_datetime(): void
    {
        $moment = CarbonImmutable::parse('2026-09-13 14:05:00', 'Asia/Karachi');

        foreach (['Y-m-d' => '2026-09-13', 'd/m/Y' => '13/09/2026', 'm/d/Y' => '09/13/2026', 'j F Y' => '13 September 2026'] as $format => $expected) {
            $this->saveLocalization(['date_format' => $format]);

            $this->assertSame($expected, app_date($moment), 'date_format '.$format);
            $this->assertSame($expected.' 02:05 PM', app_datetime($moment), 'date_format '.$format.' inside app_datetime');
        }
    }

    #[Test]
    public function changing_the_time_format_changes_app_time(): void
    {
        $this->saveLocalization(['time_format' => 'H:i']);

        $this->assertSame('14:05', app_time(CarbonImmutable::parse('2026-09-13 14:05:00', 'Asia/Karachi')));
    }

    #[Test]
    public function a_rendered_page_follows_the_saved_date_format(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveLocalization(['date_format' => 'Y-m-d', 'time_format' => 'H:i'], $admin);

        // The group footer renders "last updated" through app_datetime(): the save above just
        // stamped it, so the page must show that moment in the new format.
        $stamp = Setting::query()->where('group', 'localization')->max('updated_at');
        $expected = app_datetime($stamp);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $expected);

        $this->actingAs($admin)
            ->get('/admin/settings/localization')
            ->assertOk()
            ->assertSee('title="'.$expected.'"', false);
    }

    #[Test]
    public function the_timezone_setting_moves_app_date_across_midnight(): void
    {
        // 21:30 UTC on the 13th is already the 14th in Karachi (UTC+5).
        $moment = CarbonImmutable::parse('2026-09-13 21:30:00', 'UTC');

        $this->saveLocalization(['date_format' => 'Y-m-d', 'timezone' => 'Asia/Karachi']);
        $this->assertSame('2026-09-14', app_date($moment));

        $this->saveLocalization(['timezone' => 'America/New_York']);
        $this->assertSame('2026-09-13', app_date($moment));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function saveLocalization(array $overrides, ?User $admin = null): void
    {
        $admin ??= $this->createSuperAdmin();

        $this->actingAs($admin)
            ->from('/admin/settings/localization')
            ->put('/admin/settings/localization', $this->browserPayload($admin, 'localization', $overrides))
            ->assertRedirect('/admin/settings/localization')
            ->assertSessionHasNoErrors();

        app(SettingsRepository::class)->flush();
    }
}
