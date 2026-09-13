<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Validation": the registry's rules reject a bad email, a negative
 * `minimum_payout`, an out-of-range tax rate, an unknown currency and an invalid timezone — on the
 * screen and again inside `SettingsService`, because a later phase will call the service from
 * places no Form Request guards. A readonly key and a key from another group are refused.
 *
 * Every rejection is also checked for its side effects: the stored value does not move and no
 * activity row is written.
 */
final class SettingsValidationTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'contact email without a domain' => ['contact', 'email', 'info@'],
            'contact email that is just words' => ['contact', 'email', 'not an email'],
            'support email' => ['contact', 'support_email', 'support.example.test'],
            'mail from address' => ['mail', 'from_address', 'no-reply'],
            'mail reply-to' => ['mail', 'reply_to', 'reply@@example.test'],
            'negative minimum payout' => ['collaborator', 'minimum_payout', '-1'],
            'negative minimum payout by a paisa' => ['collaborator', 'minimum_payout', '-0.01'],
            'non-numeric minimum payout' => ['collaborator', 'minimum_payout', 'one thousand'],
            'tax rate above 100' => ['finance', 'default_tax_rate', '100.01'],
            'tax rate far above 100' => ['finance', 'default_tax_rate', '250'],
            'negative tax rate' => ['finance', 'default_tax_rate', '-5'],
            // Addresses egulias' RFC validation accepts although they carry a CR/LF fold: saved as the
            // from or reply-to address, Symfony refuses to build the header and every send throws. The
            // fold sits inside the value, so the TrimStrings middleware cannot strip it on the way in.
            'contact email with a folded local part' => ['contact', 'email', "hello\r\n @example.test"],
            'support email with a folded comment' => ['contact', 'support_email', "(desk\r\n )support@example.test"],
            'mail from address with a folded quoted string' => ['mail', 'from_address', "\"no\r\n reply\"@example.test"],
            'mail reply-to with a bare line feed' => ['mail', 'reply_to', "reply\n @example.test"],
            // bcmath cannot read exponent notation, so a stored `1e3` would make every Money call on
            // the setting throw.
            'minimum payout in exponent notation' => ['collaborator', 'minimum_payout', '1e3'],
            'student commission rate in exponent notation' => ['collaborator', 'default_student_commission_rate', '1E1'],
            'project commission rate in exponent notation' => ['collaborator', 'default_project_commission_rate', '2.5e1'],
            'tax rate in exponent notation' => ['finance', 'default_tax_rate', '1e1'],
            // The seeded commission types are `percentage`, so these rates are percentages.
            'student commission percentage above 100' => ['collaborator', 'default_student_commission_rate', '150'],
            'project commission percentage above 100' => ['collaborator', 'default_project_commission_rate', '100.5'],
            'unknown currency code' => ['localization', 'currency', 'XYZ'],
            'crypto is not a currency here' => ['localization', 'currency', 'BTC'],
            'lower-case currency code' => ['localization', 'currency', 'usd'],
            'invented timezone' => ['localization', 'timezone', 'Mars/Olympus_Mons'],
            'a city that is not a tz identifier' => ['localization', 'timezone', 'Asia/Lahore'],
            'offset instead of identifier' => ['localization', 'timezone', 'GMT+5'],
        ];
    }

    #[Test]
    #[DataProvider('invalidValueProvider')]
    public function the_settings_screen_rejects_an_invalid_value(string $group, string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();

        $before = $this->rawSetting($group.'.'.$key);
        $payload = $this->browserPayload($admin, $group, [$key => $value]);
        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->from('/admin/settings/'.$group)
            ->put('/admin/settings/'.$group, $payload)
            ->assertRedirect('/admin/settings/'.$group)
            ->assertSessionHasErrors('settings.'.$key);

        $this->assertSame($before, $this->rawSetting($group.'.'.$key), 'A rejected value must not be stored.');
        $this->assertCount(0, $this->settingsActivitySince($since), 'A rejected save writes no activity.');
    }

    #[Test]
    #[DataProvider('invalidValueProvider')]
    public function the_settings_service_rejects_the_same_value_on_its_own(string $group, string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();
        $before = $this->rawSetting($group.'.'.$key);

        try {
            app(SettingsService::class)->update($group, [$key => $value], $admin);
            $this->fail(sprintf('SettingsService accepted %s.%s = %s.', $group, $key, var_export($value, true)));
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }

        $this->assertSame($before, $this->rawSetting($group.'.'.$key));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function boundaryValueProvider(): array
    {
        return [
            'zero minimum payout' => ['collaborator', 'minimum_payout', '0'],
            'zero tax' => ['finance', 'default_tax_rate', '0'],
            'exactly 100 percent tax' => ['finance', 'default_tax_rate', '100'],
            'exactly 100 percent commission' => ['collaborator', 'default_student_commission_rate', '100'],
            'a plain decimal minimum payout' => ['collaborator', 'minimum_payout', '2500.50'],
            'a listed currency' => ['localization', 'currency', 'USD'],
            'UTC' => ['localization', 'timezone', 'UTC'],
            'a real Pakistani timezone' => ['localization', 'timezone', 'Asia/Karachi'],
            'a plus-addressed email' => ['contact', 'email', 'hello+admissions@example.test'],
        ];
    }

    #[Test]
    #[DataProvider('boundaryValueProvider')]
    public function the_valid_edge_of_each_rule_is_accepted(string $group, string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/'.$group, $this->browserPayload($admin, $group, [$key => $value]))
            ->assertSessionHasNoErrors();

        $raw = $this->rawSetting($group.'.'.$key);

        if ((SettingsRegistry::field($group.'.'.$key)['storage'] ?? null) === 'decimal') {
            // A decimal may be normalised to its column scale on the way in ('100' => '100.0000');
            // what matters is that the accepted value is stored as a plain decimal equal to it.
            $this->assertMatchesRegularExpression('/^-?\d+(\.\d+)?$/', (string) $raw, 'A decimal setting is stored as a plain decimal string.');
            $this->assertSame(0, bccomp((string) $raw, $value, 4), sprintf('%s.%s stored %s for %s.', $group, $key, var_export($raw, true), $value));

            return;
        }

        $this->assertSame($value, $raw);
    }

    /*
    |--------------------------------------------------------------------------
    | Keys the group may not write
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_readonly_key_is_refused_on_the_screen(): void
    {
        $admin = $this->createSuperAdmin();

        $payload = $this->browserPayload($admin, 'security', ['two_factor_enabled' => '1']);

        $this->actingAs($admin)
            ->from('/admin/settings/security')
            ->put('/admin/settings/security', $payload)
            ->assertSessionHasErrors('settings.two_factor_enabled');

        $this->assertSame('0', $this->rawSetting('security.two_factor_enabled'));
    }

    #[Test]
    public function a_readonly_key_is_refused_by_the_service(): void
    {
        $admin = $this->createSuperAdmin();

        $this->expectException(ActionNotAllowedException::class);

        try {
            app(SettingsService::class)->update('security', ['two_factor_enabled' => true], $admin);
        } finally {
            $this->assertSame('0', $this->rawSetting('security.two_factor_enabled'));
        }
    }

    #[Test]
    public function a_key_belonging_to_another_group_is_refused_and_names_its_owner(): void
    {
        $admin = $this->createSuperAdmin();

        $hostBefore = $this->rawSetting('mail.host');
        $payload = $this->browserPayload($admin, 'company');
        $payload['settings']['host'] = 'smtp.attacker.test';

        $this->actingAs($admin)
            ->from('/admin/settings/company')
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasErrors([
                'settings.host' => '[host] belongs to the mail settings, not to company.',
            ]);

        $this->assertSame($hostBefore, $this->rawSetting('mail.host'));
        $this->assertNull($this->rawSetting('company.host'), 'A foreign key must never materialise as a row in the wrong group.');
    }

    #[Test]
    public function an_admin_without_the_mail_permission_cannot_smuggle_smtp_settings_through_another_group(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $payload = $this->browserPayload($admin, 'company');
        $payload['settings']['password'] = 'smuggled';
        $payload['settings']['host'] = 'smtp.attacker.test';

        $this->actingAs($admin)
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasErrors(['settings.password', 'settings.host']);

        $this->assertNull($this->rawSetting('mail.password'));
    }

    #[Test]
    public function an_unknown_key_is_refused(): void
    {
        $admin = $this->createSuperAdmin();

        $payload = $this->browserPayload($admin, 'company');
        $payload['settings']['is_super_admin'] = '1';

        $this->actingAs($admin)
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasErrors(['settings.is_super_admin' => '[is_super_admin] is not a setting.']);

        $this->assertNull($this->rawSetting('company.is_super_admin'));
    }

    #[Test]
    public function the_service_never_writes_a_key_the_group_does_not_declare(): void
    {
        $admin = $this->createSuperAdmin();
        $hostBefore = $this->rawSetting('mail.host');

        app(SettingsService::class)->update('company', ['name' => 'Declared Only Ltd', 'host' => 'smtp.attacker.test', 'mail.host' => 'x'], $admin);

        $this->assertSame('Declared Only Ltd', $this->rawSetting('company.name'));
        $this->assertSame($hostBefore, $this->rawSetting('mail.host'));
        $this->assertNull($this->rawSetting('company.host'));
    }

    #[Test]
    public function an_unknown_group_is_a_404(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->get('/admin/settings/not_a_group')->assertNotFound();
        $this->actingAs($admin)->put('/admin/settings/not_a_group', ['settings' => ['name' => 'x']])->assertNotFound();
        $this->actingAs($admin)->post('/admin/settings/not_a_group/reset')->assertNotFound();
    }
}
