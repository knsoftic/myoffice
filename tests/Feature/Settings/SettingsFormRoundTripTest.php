<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * The settings screen and the settings validator must agree about every group.
 *
 * The cheapest possible save — open a group, change nothing, press Save — has to succeed, move no
 * value and write no activity row. Anything else means the screen renders a control the Form
 * Request refuses (or one whose posted shape differs from what is stored), and an administrator is
 * locked out of that whole group for a reason no field on the page explains.
 *
 * Booleans get their own contract here too: a switch has two states, the one the administrator
 * just turned off must be stored as `false`, and it must still be off on the next render and the
 * next save.
 */
final class SettingsFormRoundTripTest extends TestCase
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
     * @return array<string, array{0: string}>
     */
    public static function groupProvider(): array
    {
        $groups = [];

        foreach (array_keys(SettingsRegistry::groups()) as $group) {
            $groups[$group] = [$group];
        }

        return $groups;
    }

    #[Test]
    public function the_registry_declares_the_fifteen_groups_this_suite_walks(): void
    {
        $this->assertSame(
            ['company', 'branding', 'appearance', 'localization', 'contact', 'social', 'seo', 'mail', 'website', 'collaborator', 'institute', 'finance', 'security', 'crm', 'maintenance'],
            array_keys(self::groupProvider()),
        );
    }

    #[Test]
    #[DataProvider('groupProvider')]
    public function saving_a_group_exactly_as_rendered_is_accepted_and_changes_nothing(string $group): void
    {
        $admin = $this->createSuperAdmin();

        $before = $this->storedValues($group);
        $payload = $this->browserPayload($admin, $group);
        $since = $this->lastActivityId();

        $response = $this->actingAs($admin)
            ->from('/admin/settings/'.$group)
            ->put('/admin/settings/'.$group, $payload);

        $response->assertRedirect('/admin/settings/'.$group);

        $errors = session('errors')?->getBag('default')->all() ?? [];

        $this->assertSame(
            [],
            $errors,
            sprintf(
                "Re-submitting the untouched [%s] form was refused. The screen renders something its own validator rejects:\n  %s",
                $group,
                implode("\n  ", $errors),
            )
        );

        $toast = session('toast');
        $this->assertIsArray($toast);
        $this->assertNotSame('error', $toast['type'], sprintf('[%s] save failed: %s', $group, $toast['message'] ?? ''));

        $this->assertSame($before, $this->storedValues($group), sprintf('An untouched [%s] save must not move a stored value.', $group));

        $logged = $this->settingsActivitySince($since)->map(fn ($row): string => (string) ($row->properties['key'] ?? '?'))->all();

        $this->assertSame(
            [],
            $logged,
            sprintf('An untouched [%s] save must write no activity, but logged: %s', $group, implode(', ', $logged))
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Booleans: two states, never three
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_editable_switch_posts_a_hidden_zero_ahead_of_its_checkbox(): void
    {
        $admin = $this->createSuperAdmin();
        $checked = 0;

        foreach (SettingsRegistry::all() as $group => $fields) {
            $switches = array_filter(
                $fields,
                static fn (array $field): bool => $field['type'] === SettingsRegistry::TYPE_BOOLEAN && $field['readonly'] === false,
            );

            if ($switches === []) {
                continue;
            }

            $xpath = $this->xpath((string) $this->actingAs($admin)->get('/admin/settings/'.$group)->assertOk()->getContent());

            foreach (array_keys($switches) as $key) {
                $name = 'settings['.$key.']';

                $hidden = $xpath->query(sprintf('//input[@type="hidden"][@name="%s"][@value="0"]', $name));
                $box = $xpath->query(sprintf('//input[@type="checkbox"][@name="%s"]', $name));

                $this->assertSame(1, $hidden->length, sprintf('%s.%s: an unchecked switch must still post "0".', $group, $key));
                $this->assertSame(1, $box->length, sprintf('%s.%s renders no checkbox.', $group, $key));
                $this->assertSame('1', $box->item(0)?->getAttribute('value'));

                $this->assertSame(
                    1,
                    $xpath->query(sprintf('//input[@type="hidden"][@name="%1$s"]/following::input[@type="checkbox"][@name="%1$s"]', $name))->length,
                    sprintf('%s.%s: the hidden "0" must come BEFORE the checkbox, or the "0" wins when it is ticked.', $group, $key)
                );

                $checked++;
            }
        }

        // The registry declares 17 editable switches today (the original floor of 20 over-counted
        // them); the floor exists only to catch a scan that silently stopped finding any.
        $this->assertGreaterThanOrEqual(15, $checked, 'The scan found almost no switches — it has stopped looking in the right place.');
    }

    #[Test]
    public function a_readonly_switch_posts_nothing_at_all(): void
    {
        $admin = $this->createSuperAdmin();

        $readonly = array_filter(
            SettingsRegistry::fields('security'),
            static fn (array $field): bool => $field['readonly'] === true && $field['type'] === SettingsRegistry::TYPE_BOOLEAN,
        );

        $this->assertArrayHasKey('two_factor_enabled', $readonly, 'security.two_factor_enabled is the declared readonly switch.');

        $pairs = $this->successfulControls(
            (string) $this->actingAs($admin)->get('/admin/settings/security')->assertOk()->getContent(),
            '/admin/settings/security',
        );

        foreach (array_keys($readonly) as $key) {
            $posted = array_values(array_filter($pairs, static fn (array $pair): bool => $pair[0] === 'settings['.$key.']'));

            $this->assertSame(
                [],
                $posted,
                sprintf('security.%s is read-only: its disabled switch must submit nothing, yet the form posts %s.', $key, json_encode($posted)),
            );
        }
    }

    #[Test]
    public function a_switch_turned_off_on_the_screen_is_stored_as_false_and_stays_off(): void
    {
        $admin = $this->createSuperAdmin();

        app(SettingsService::class)->update('appearance', ['show_powered_by' => true], $admin);
        $this->assertTrue($this->freshSetting('appearance.show_powered_by', false));

        // The administrator unticks the box: the browser posts only the hidden "0".
        $payload = $this->browserPayload($admin, 'appearance', uncheck: ['show_powered_by']);
        $this->assertSame('0', $payload['settings']['show_powered_by']);

        $this->actingAs($admin)->put('/admin/settings/appearance', $payload)->assertSessionHasNoErrors();

        $this->assertSame('0', $this->rawSetting('appearance.show_powered_by'), 'Stored as "0" — not NULL, not absent.');
        $this->assertFalse($this->freshSetting('appearance.show_powered_by', true), 'Reads back false whatever default the caller passes.');

        // Next visit: the box renders unticked…
        $xpath = $this->xpath((string) $this->actingAs($admin)->get('/admin/settings/appearance')->getContent());
        $box = $xpath->query('//input[@type="checkbox"][@name="settings[show_powered_by]"]')->item(0);
        $this->assertNotNull($box);
        $this->assertFalse($box->hasAttribute('checked'), 'A switch the administrator turned off must render off.');

        // …and saving the page again without touching it cannot switch it back on.
        $this->actingAs($admin)
            ->put('/admin/settings/appearance', $this->browserPayload($admin, 'appearance'))
            ->assertSessionHasNoErrors();

        $this->assertSame('0', $this->rawSetting('appearance.show_powered_by'));
        $this->assertFalse($this->freshSetting('appearance.show_powered_by', true));
    }

    #[Test]
    public function a_switch_whose_value_arrives_empty_is_written_as_false_not_null(): void
    {
        $admin = $this->createSuperAdmin();

        foreach (['', null] as $posted) {
            app(SettingsService::class)->update('maintenance', ['contact_form_enabled' => true], $admin);

            $payload = $this->browserPayload($admin, 'maintenance', ['contact_form_enabled' => $posted]);

            $this->actingAs($admin)->put('/admin/settings/maintenance', $payload)->assertSessionHasNoErrors();

            $this->assertSame(
                '0',
                $this->rawSetting('maintenance.contact_form_enabled'),
                'A switch posted as '.var_export($posted, true).' must be stored as "0".'
            );
            $this->assertFalse($this->freshSetting('maintenance.contact_form_enabled', true));
        }
    }

    #[Test]
    public function a_switch_turned_on_is_stored_as_true(): void
    {
        $admin = $this->createSuperAdmin();

        app(SettingsService::class)->update('finance', ['tax_enabled' => false], $admin);

        $this->actingAs($admin)
            ->put('/admin/settings/finance', $this->browserPayload($admin, 'finance', check: ['tax_enabled']))
            ->assertSessionHasNoErrors();

        $this->assertSame('1', $this->rawSetting('finance.tax_enabled'));
        $this->assertTrue($this->freshSetting('finance.tax_enabled', false));
    }

    #[Test]
    public function no_boolean_setting_row_is_ever_left_null(): void
    {
        $nulls = Setting::query()
            ->where('type', 'boolean')
            ->whereNull('value')
            ->get()
            ->map(fn (Setting $row): string => $row->group.'.'.$row->key)
            ->all();

        $this->assertSame([], $nulls, 'A seeded switch holding NULL reads as whatever default each caller passes.');
    }

    /**
     * @return array<string, string|null>
     */
    private function storedValues(string $group): array
    {
        return DB::table('settings')
            ->where('group', $group)
            ->orderBy('key')
            ->pluck('value', 'key')
            ->map(static fn (mixed $value): ?string => $value === null ? null : (string) $value)
            ->all();
    }
}
