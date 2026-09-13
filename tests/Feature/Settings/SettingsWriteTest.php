<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Settings write" and "Reset".
 *
 *   · updating a group persists, flushes the cache, and writes one activity row per changed key
 *     with old and new values;
 *   · resetting a group restores registry defaults and logs it.
 *
 * Every save here goes through the real screen: the form is rendered, serialised the way a browser
 * submits it, edited, and PUT back — so the assertions are about what an administrator actually
 * does, not about a payload shaped to suit the validator.
 */
final class SettingsWriteTest extends TestCase
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

    #[Test]
    public function saving_a_group_persists_the_changed_values(): void
    {
        $admin = $this->createSuperAdmin();

        $payload = $this->browserPayload($admin, 'company', [
            'name' => 'Acme Learning Systems',
            'tagline' => 'Software and skills, delivered.',
            'founded_year' => '2019',
        ]);

        $this->actingAs($admin)
            ->from('/admin/settings/company')
            ->put('/admin/settings/company', $payload)
            ->assertRedirect('/admin/settings/company')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');

        $this->assertSame('Acme Learning Systems', $this->rawSetting('company.name'));
        $this->assertSame('Software and skills, delivered.', $this->rawSetting('company.tagline'));
        $this->assertSame('2019', $this->rawSetting('company.founded_year'));

        $this->assertSame('Acme Learning Systems', $this->freshSetting('company.name'));
        $this->assertSame(2019, $this->freshSetting('company.founded_year'), 'A number field reads back as an integer.');
    }

    #[Test]
    public function a_save_flushes_the_cached_settings_payload(): void
    {
        $admin = $this->createSuperAdmin();
        $repository = app(SettingsRepository::class);

        // Warm the cache, then poison it: from here on the only way a read can see the truth is
        // for the write path to throw this payload away.
        $repository->flush();
        $this->assertNotNull(setting('company.name'));

        $cached = Cache::get(SettingsRepository::CACHE_KEY);
        $this->assertIsArray($cached, 'Settings are served from one cached payload.');

        $cached['company.name']['value'] = 'Stale Name From Cache';
        $repository->flush();
        Cache::forever(SettingsRepository::CACHE_KEY, $cached);

        $this->assertSame('Stale Name From Cache', setting('company.name'), 'The read path must actually be the cache.');

        $payload = $this->browserPayload($admin, 'company', ['name' => 'Fresh Name After Save']);

        // Rendering the form must not have refreshed the cache on its own.
        $this->assertSame('Stale Name From Cache', setting('company.name'));

        $this->actingAs($admin)
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasNoErrors();

        // No manual flush: the very next read in this process must see the saved value.
        $this->assertSame('Fresh Name After Save', setting('company.name'));

        $after = Cache::get(SettingsRepository::CACHE_KEY);

        if (is_array($after)) {
            $this->assertSame(
                'Fresh Name After Save',
                $after['company.name']['value'] ?? null,
                'Whatever is cached after the save must be the saved value, never the stale payload.'
            );
        }
    }

    #[Test]
    public function each_changed_key_writes_exactly_one_activity_row_carrying_old_and_new_values(): void
    {
        $admin = $this->createSuperAdmin();

        $oldName = (string) $this->freshSetting('company.name');
        $oldTagline = (string) $this->freshSetting('company.tagline');

        $payload = $this->browserPayload($admin, 'company', [
            'name' => 'Northwind Institute',
            'tagline' => 'Learn by building.',
        ]);

        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasNoErrors();

        $rows = $this->settingsActivitySince($since);

        $this->assertCount(
            2,
            $rows,
            'Two keys changed and the rest of the group was resubmitted untouched, so exactly two activity rows: '
            .$rows->map(fn ($row) => $row->properties['key'] ?? '?')->implode(', ')
        );

        $byKey = $rows->keyBy(fn ($row): string => (string) $row->properties['key']);

        $this->assertEqualsCanonicalizing(['company.name', 'company.tagline'], $byKey->keys()->all());

        foreach (['company.name' => [$oldName, 'Northwind Institute'], 'company.tagline' => [$oldTagline, 'Learn by building.']] as $key => [$old, $new]) {
            $row = $byKey[$key];

            $this->assertSame($old, $row->properties['old']['value'], $key.': the activity row must carry the old value.');
            $this->assertSame($new, $row->properties['attributes']['value'], $key.': the activity row must carry the new value.');
            $this->assertSame((int) $admin->getKey(), (int) $row->causer_id, $key.': the actor is the causer.');
            $this->assertSame(Setting::class, $row->subject_type);

            [$group, $name] = explode('.', $key, 2);
            $setting = Setting::query()->where('group', $group)->where('key', $name)->firstOrFail();

            $this->assertSame((int) $setting->getKey(), (int) $row->subject_id, $key.': the subject is the settings row.');
            $this->assertSame((int) $admin->getKey(), (int) $setting->updated_by, $key.': updated_by is stamped.');
        }

        $untouched = Setting::query()->where('group', 'company')->where('key', 'legal_name')->firstOrFail();
        $this->assertNull($untouched->updated_by, 'A key that did not change is not stamped.');
    }

    #[Test]
    public function the_audit_values_are_typed_not_raw_column_strings(): void
    {
        $admin = $this->createSuperAdmin();

        app(SettingsService::class)->update('collaborator', ['payout_request_enabled' => true, 'minimum_payout' => '1000.00'], $admin);

        $payload = $this->browserPayload($admin, 'collaborator', ['minimum_payout' => '2500.50'], uncheck: ['payout_request_enabled']);

        $since = $this->lastActivityId();

        $this->actingAs($admin)->put('/admin/settings/collaborator', $payload)->assertSessionHasNoErrors();

        $byKey = $this->settingsActivitySince($since)->keyBy(fn ($row): string => (string) $row->properties['key']);

        $this->assertArrayHasKey('collaborator.payout_request_enabled', $byKey->all());
        $this->assertTrue($byKey['collaborator.payout_request_enabled']->properties['old']['value']);
        $this->assertFalse($byKey['collaborator.payout_request_enabled']->properties['attributes']['value']);

        $this->assertArrayHasKey('collaborator.minimum_payout', $byKey->all());
        $this->assertSame('1000.00', $byKey['collaborator.minimum_payout']->properties['old']['value']);
        $this->assertSame('2500.50', $byKey['collaborator.minimum_payout']->properties['attributes']['value'], 'Money stays a string — never a float.');
    }

    #[Test]
    public function saving_without_changing_anything_writes_no_activity_and_stamps_nobody(): void
    {
        $admin = $this->createSuperAdmin();

        $payload = $this->browserPayload($admin, 'company');
        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $this->settingsActivitySince($since));
        $this->assertSame(0, Setting::query()->where('group', 'company')->whereNotNull('updated_by')->count());
    }

    #[Test]
    public function the_group_footer_names_who_last_saved_it(): void
    {
        $admin = $this->createSuperAdmin(['name' => 'Farah Siddiqui']);

        $this->actingAs($admin)
            ->put('/admin/settings/company', $this->browserPayload($admin, 'company', ['name' => 'Footer Check Ltd']))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get('/admin/settings/company')
            ->assertOk()
            ->assertSee('Last updated', false)
            ->assertSee('Farah Siddiqui', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resetting_a_group_restores_every_registry_default_and_logs_each_moved_key(): void
    {
        $admin = $this->createSuperAdmin();
        $service = app(SettingsService::class);

        // `invoice_next_number` is a readonly document counter (D62): it is not a value a reset may
        // move, and SettingsDocumentCounterTest proves a reset leaves it where DocumentNumberService
        // put it.
        $service->update('finance', [
            'invoice_prefix' => 'ACME',
            'tax_enabled' => true,
            'default_tax_rate' => '17.5000',
            'payment_terms_days' => 30,
            'bank_details' => 'Meezan Bank, IBAN PK00 0000',
        ], $admin);

        $moved = ['invoice_prefix', 'tax_enabled', 'default_tax_rate', 'payment_terms_days', 'bank_details'];

        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->from('/admin/settings/finance')
            ->post('/admin/settings/finance/reset')
            ->assertRedirect('/admin/settings/finance')
            ->assertSessionHas('toast');

        app(SettingsRepository::class)->flush();

        foreach (SettingsRegistry::fields('finance') as $key => $field) {
            if ($field['readonly'] === true) {
                continue;
            }

            $this->assertSame(
                $field['default'],
                setting('finance.'.$key),
                sprintf('finance.%s must be back at its registry default after a reset.', $key)
            );
        }

        $rows = $this->settingsActivitySince($since);

        $this->assertEqualsCanonicalizing(
            array_map(static fn (string $key): string => 'finance.'.$key, $moved),
            $rows->map(fn ($row): string => (string) $row->properties['key'])->all(),
            'A reset logs one row for every key it moved and nothing for a key already at its default.'
        );

        foreach ($rows as $row) {
            $this->assertSame('Setting reset to default', $row->description);
            $this->assertSame((int) $admin->getKey(), (int) $row->causer_id);
        }
    }

    #[Test]
    public function resetting_leaves_other_groups_and_readonly_keys_alone(): void
    {
        $admin = $this->createSuperAdmin();
        $service = app(SettingsService::class);

        $service->update('company', ['name' => 'Survives The Finance Reset'], $admin);
        $service->update('security', ['password_min_length' => 14], $admin);

        $this->actingAs($admin)->post('/admin/settings/security/reset')->assertRedirect();

        $this->assertSame('Survives The Finance Reset', $this->freshSetting('company.name'));
        $this->assertSame(10, $this->freshSetting('security.password_min_length'));
        $this->assertFalse($this->freshSetting('security.two_factor_enabled', true), 'The readonly key keeps its value and is never flipped by a reset.');
    }
}
