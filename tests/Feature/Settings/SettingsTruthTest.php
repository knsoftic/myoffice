<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The three defects the Phase 2 finishing pass closed, each pinned by the cheapest test that would
 * have caught it on the day it was introduced.
 *
 * 1. **Split settings truth.** `SettingsRegistry` relocated the company identity keys into
 *    `branding` and `contact`, but three Blade partials kept reading the Phase 1 keys — so an
 *    administrator's logo and contact edits changed one row while every page rendered another, with
 *    no error anywhere. The guard is a grep: every key a view reads must be declared by the
 *    registry, and none of them may be a row the supersede migration marked deprecated.
 *
 * 2. **A null boolean read back as the caller's default.** `SettingsService::serialise()` returned
 *    before its `match`, so a boolean written as null stored SQL NULL; `SettingsRepository::get()`
 *    then found a row whose value was null and handed back `$default` — so
 *    `setting('appearance.show_powered_by', true)` reported a switch an administrator had turned
 *    off as still on, and the answer depended on what each caller happened to pass.
 *
 * 3. **"SMTP needs a host" enforced nowhere.** The registry's rules are per-field by design, so
 *    `mail.host`'s own help text was a promise no code kept: `mailer` could be saved as `smtp` with
 *    `host` empty, and the failure surfaced later as a transport error instead of a field error.
 *
 * All three are class-of-defect tests, not one-off repairs: (1) scans the views as they are now,
 * (2) and (3) go through the public write path both the screen and a later phase's service will
 * use.
 */
final class SettingsTruthTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * The label prefix `2026_09_12_060400_supersede_relocated_setting_keys` writes onto a row whose
     * concept moved to another key.
     */
    private const DEPRECATED_PREFIX = 'Deprecated - use ';

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        $this->settings = app(SettingsRepository::class);
        $this->settings->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | 1. A key a view reads is a key the registry declares
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_setting_a_view_reads_is_declared_by_the_registry(): void
    {
        $keys = $this->keysReadByViews();

        $this->assertNotSame([], $keys, 'The scan found no setting() call at all — it has stopped looking in the right place.');

        $undeclared = [];

        foreach ($keys as $key => $files) {
            if (! SettingsRegistry::has($key)) {
                $undeclared[$key] = $files;
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'These keys are read by a view but declared nowhere, so nothing on the settings screen '.
            "can ever change them:\n".$this->describe($undeclared)
        );
    }

    #[Test]
    public function no_view_reads_a_superseded_settings_row(): void
    {
        // The migration's own pair list, not only the rows it has marked: on a fresh install the
        // migration runs before the seeder and marks nothing, and a scan against an empty list
        // would pass whatever the views read.
        $superseded = [];

        foreach ($this->supersededPairs() as $legacy => $canonical) {
            $superseded[$legacy] = self::DEPRECATED_PREFIX.$canonical;
        }

        $superseded += DB::table('settings')
            ->where('label', 'like', self::DEPRECATED_PREFIX.'%')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [$row->group.'.'.$row->key => (string) $row->label])
            ->all();

        $this->assertNotSame([], $superseded, 'The supersede migration declares no pairs — the scan has nothing to guard.');

        $offenders = [];

        foreach ($this->keysReadByViews() as $key => $files) {
            if (array_key_exists($key, $superseded)) {
                $offenders[$key.' ('.$superseded[$key].')'] = $files;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These views read a row whose concept has moved, so the settings screen edits one row \n".
            'while the page renders another:'."\n".$this->describe($offenders)
        );
    }

    #[Test]
    public function a_superseded_row_is_readonly_and_is_never_declared_again(): void
    {
        // A fresh install never holds the Phase 1 rows (`migrate` runs before `db:seed`, so the
        // migration is a documented no-op there). Build the upgraded database it exists for.
        $this->seedPhaseOneLegacyRows();

        $this->supersedeMigration()->up();

        $pairs = $this->supersededPairs();
        $declared = SettingsRegistry::keys();

        $rows = DB::table('settings')
            ->where('label', 'like', self::DEPRECATED_PREFIX.'%')
            ->get()
            ->keyBy(static fn (object $row): string => $row->group.'.'.$row->key);

        $this->assertEqualsCanonicalizing(
            array_keys($pairs),
            $rows->keys()->all(),
            'Every legacy row the migration names must be marked superseded, and nothing else.'
        );

        foreach ($rows as $key => $row) {
            $this->assertTrue(
                (bool) $row->is_readonly,
                sprintf('[%s] is marked superseded but is still editable.', $key)
            );

            $this->assertStringStartsWith(
                self::DEPRECATED_PREFIX.$pairs[$key],
                (string) $row->label,
                sprintf('[%s] must name the key that replaced it.', $key)
            );

            $this->assertNotContains(
                $key,
                $declared,
                sprintf('[%s] is stored as superseded and declared by the registry: a key has one home.', $key)
            );

            $this->assertContains(
                $pairs[$key],
                $declared,
                sprintf('[%s] is superseded by [%s], which the registry does not declare — the value would be stranded.', $key, $pairs[$key])
            );

            $this->assertSame('legacy:'.$key, $row->value, sprintf('[%s] kept its own value: nothing is cleared.', $key));
        }
    }

    #[Test]
    public function superseding_fills_only_an_empty_canonical_row_and_is_idempotent_and_reversible(): void
    {
        $this->seedPhaseOneLegacyRows();

        // One canonical row an administrator already filled, one left empty.
        $this->putRaw('contact.phone', '+92 300 1234567');
        $this->putRaw('contact.email', null);
        $indexable = $this->rawValue('seo.robots_indexable');

        $migration = $this->supersedeMigration();
        $migration->up();

        $this->assertSame('+92 300 1234567', $this->rawValue('contact.phone'), 'A value already set on the canonical key must never be overwritten.');
        $this->assertSame('legacy:company.email', $this->rawValue('contact.email'), 'An empty canonical row is filled from the legacy row.');
        $this->assertSame($indexable, $this->rawValue('seo.robots_indexable'), 'A mark-only pair must not copy a select string into a boolean.');

        $afterFirstRun = $this->settingsSnapshot();
        $migration->up();
        $this->assertSame($afterFirstRun, $this->settingsSnapshot(), 'A second run must write nothing.');

        $migration->down();

        foreach (array_keys($this->supersededPairs()) as $key) {
            [$group, $name] = explode('.', $key, 2);
            $row = DB::table('settings')->where('group', $group)->where('key', $name)->first();

            $this->assertNotNull($row);
            $this->assertFalse((bool) $row->is_readonly, sprintf('[%s] must be editable again after down().', $key));
            $this->assertSame('Legacy '.$key, $row->label, sprintf('[%s] must get its original label back.', $key));
            $this->assertSame('legacy:'.$key, $row->value);
        }

        // down() never moves a value back: the copied-forward value may since have been edited.
        $this->assertSame('legacy:company.email', $this->rawValue('contact.email'));
    }

    #[Test]
    public function every_declared_key_exists_as_a_row_after_seeding(): void
    {
        $stored = DB::table('settings')
            ->get()
            ->map(static fn (object $row): string => $row->group.'.'.$row->key)
            ->all();

        $missing = array_values(array_diff(SettingsRegistry::keys(), $stored));

        $this->assertSame([], $missing, 'SettingSeeder left these declared keys unseeded: '.implode(', ', $missing));
    }

    /*
    |--------------------------------------------------------------------------
    | 2. A boolean setting has two states, not three
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_boolean_written_as_null_stores_false_and_reads_back_false(): void
    {
        $service = app(SettingsService::class);
        $actor = $this->createSuperAdmin();

        // On, explicitly, so the null below is a real change rather than a no-op.
        $service->update('appearance', ['show_powered_by' => true], $actor);
        $this->settings->flush();
        $this->assertTrue(setting('appearance.show_powered_by', false));

        $service->update('appearance', ['show_powered_by' => null], $actor);
        $this->settings->flush();

        $stored = DB::table('settings')
            ->where('group', 'appearance')
            ->where('key', 'show_powered_by')
            ->value('value');

        $this->assertSame('0', (string) $stored, 'A null on a boolean field must store "0", never SQL NULL.');

        // The read must not depend on the default the caller happens to pass.
        $this->assertFalse(setting('appearance.show_powered_by', true));
        $this->assertFalse(setting('appearance.show_powered_by', false));
    }

    #[Test]
    public function a_boolean_row_left_null_by_an_older_release_still_reads_false(): void
    {
        // The read-side half of the same rule: a row raw SQL (or a previous release) left null.
        DB::table('settings')
            ->where('group', 'appearance')
            ->where('key', 'show_powered_by')
            ->update(['value' => null, 'type' => 'boolean']);

        $this->settings->flush();

        $this->assertFalse(
            setting('appearance.show_powered_by', true),
            'A stored boolean row holding nothing is "off"; it must never read back as the caller’s default.'
        );
    }

    #[Test]
    public function false_and_zero_still_store_zero(): void
    {
        $service = app(SettingsService::class);
        $actor = $this->createSuperAdmin();

        foreach ([false, '0', 0] as $value) {
            $service->update('appearance', ['show_powered_by' => true], $actor);
            $this->settings->flush();

            $service->update('appearance', ['show_powered_by' => $value], $actor);
            $this->settings->flush();

            $this->assertSame(
                '0',
                (string) DB::table('settings')->where('group', 'appearance')->where('key', 'show_powered_by')->value('value'),
                'Storing '.var_export($value, true).' on a boolean field must write "0".'
            );
            $this->assertFalse(setting('appearance.show_powered_by', true));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 3. SMTP needs a host — on both write paths
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_service_refuses_an_smtp_transport_with_no_host(): void
    {
        $service = app(SettingsService::class);
        $actor = $this->createSuperAdmin();

        $service->update('mail', ['mailer' => 'log', 'host' => null], $actor);
        $this->settings->flush();

        try {
            $service->update('mail', ['mailer' => 'smtp'], $actor);
            $this->fail('Saving mailer=smtp with no stored host must be a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('host', $exception->errors());
        }

        $this->settings->flush();
        $this->assertSame('log', setting('mail.mailer'), 'The refused save must not have been applied.');
    }

    #[Test]
    public function the_service_judges_the_effective_value_not_only_the_payload(): void
    {
        $service = app(SettingsService::class);
        $actor = $this->createSuperAdmin();

        // mailer is already smtp with a host: clearing only the host is still the broken pair.
        $service->update('mail', ['mailer' => 'smtp', 'host' => 'smtp.example.com'], $actor);
        $this->settings->flush();

        try {
            $service->update('mail', ['host' => ''], $actor);
            $this->fail('Clearing the host while the stored transport is SMTP must be a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('host', $exception->errors());
        }

        $this->settings->flush();
        $this->assertSame('smtp.example.com', setting('mail.host'));
    }

    #[Test]
    public function smtp_with_a_host_is_accepted_and_a_non_smtp_transport_needs_no_host(): void
    {
        $service = app(SettingsService::class);
        $actor = $this->createSuperAdmin();

        $service->update('mail', ['mailer' => 'smtp', 'host' => 'smtp.example.com'], $actor);
        $this->settings->flush();
        $this->assertSame('smtp', setting('mail.mailer'));
        $this->assertSame('smtp.example.com', setting('mail.host'));

        $service->update('mail', ['mailer' => 'log', 'host' => null], $actor);
        $this->settings->flush();
        $this->assertSame('log', setting('mail.mailer'));
        $this->assertNull(setting('mail.host'));
    }

    #[Test]
    public function the_settings_screen_refuses_an_smtp_transport_with_no_host(): void
    {
        $actor = $this->createSuperAdmin();

        app(SettingsService::class)->update('mail', ['mailer' => 'log', 'host' => null], $actor);
        $this->settings->flush();

        $payload = $this->groupPayload('mail', ['mailer' => 'smtp', 'host' => '']);

        $this->actingAs($actor)
            ->from('/admin/settings/mail')
            ->put('/admin/settings/mail', ['settings' => $payload])
            ->assertRedirect('/admin/settings/mail')
            ->assertSessionHasErrors('settings.host');

        $this->settings->flush();
        $this->assertSame('log', setting('mail.mailer'), 'The refused save must not have been applied.');
    }

    #[Test]
    public function the_settings_screen_accepts_smtp_once_a_host_is_given(): void
    {
        $actor = $this->createSuperAdmin();

        $payload = $this->groupPayload('mail', ['mailer' => 'smtp', 'host' => 'smtp.example.com']);

        $this->actingAs($actor)
            ->from('/admin/settings/mail')
            ->put('/admin/settings/mail', ['settings' => $payload])
            ->assertRedirect('/admin/settings/mail')
            ->assertSessionHasNoErrors();

        $this->settings->flush();
        $this->assertSame('smtp', setting('mail.mailer'));
        $this->assertSame('smtp.example.com', setting('mail.host'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Every dotted key a Blade view passes to `setting()`, mapped to the files that read it.
     *
     * @return array<string, list<string>>
     */
    private function keysReadByViews(): array
    {
        $root = resource_path('views');
        $keys = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/setting\(\s*[\'"]([^\'"]+)[\'"]/', $body, $matches) === 0) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            foreach ($matches[1] as $key) {
                $keys[$key][] = $relative;

                $keys[$key] = array_values(array_unique($keys[$key]));
            }
        }

        ksort($keys);

        return $keys;
    }

    /**
     * The payload the real settings form posts for one group: every non-file field with the value
     * it holds now, plus the overrides under test.
     *
     * The screen renders every field of the group inside one form, so a genuine save always carries
     * the whole group — which is what satisfies the `required` rules `UpdateSettingsRequest` pulls
     * from the registry.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function groupPayload(string $group, array $overrides = []): array
    {
        $payload = [];

        foreach (SettingsRegistry::fields($group) as $key => $field) {
            $type = (string) $field['type'];

            if (SettingsRegistry::isFileType($type) || $field['readonly'] === true) {
                continue;
            }

            $current = setting($group.'.'.$key);

            $payload[$key] = match (true) {
                $type === SettingsRegistry::TYPE_PASSWORD => '',
                $type === SettingsRegistry::TYPE_BOOLEAN => $current ? '1' : '0',
                $type === SettingsRegistry::TYPE_MULTISELECT => is_array($current) ? array_values($current) : [],
                $type === SettingsRegistry::TYPE_JSON => is_array($current) ? $current : [],
                is_array($current) => $current,
                default => $current === null ? '' : (string) $current,
            };
        }

        return array_replace($payload, $overrides);
    }

    /**
     * The supersede migration, loaded from its file exactly as the migrator loads it.
     */
    private function supersedeMigration(): object
    {
        return require database_path('migrations/2026_09_12_060400_supersede_relocated_setting_keys.php');
    }

    /**
     * Legacy key => canonical key for every pair the migration supersedes (copied and mark-only).
     *
     * @return array<string, string>
     */
    private function supersededPairs(): array
    {
        $reflection = new ReflectionClass($this->supersedeMigration());

        /** @var array<string, string> $pairs */
        $pairs = $reflection->getConstant('PAIRS');

        /** @var array<string, string> $markOnly */
        $markOnly = $reflection->getConstant('DEPRECATE_ONLY');

        return $pairs + $markOnly;
    }

    /**
     * Recreate the rows a Phase 1 install carries for every legacy key, each with a recognisable
     * value and label.
     */
    private function seedPhaseOneLegacyRows(): void
    {
        $now = now();

        foreach (array_keys($this->supersededPairs()) as $key) {
            [$group, $name] = explode('.', $key, 2);

            DB::table('settings')->updateOrInsert(['group' => $group, 'key' => $name], [
                'value' => 'legacy:'.$key,
                'type' => 'string',
                'is_encrypted' => false,
                'is_public' => true,
                'is_readonly' => false,
                'label' => 'Legacy '.$key,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function putRaw(string $key, ?string $value): void
    {
        [$group, $name] = explode('.', $key, 2);

        $this->assertTrue(
            DB::table('settings')->where('group', $group)->where('key', $name)->exists(),
            sprintf('[%s] should have been seeded from the registry.', $key)
        );

        DB::table('settings')->where('group', $group)->where('key', $name)->update(['value' => $value]);
    }

    private function rawValue(string $key): ?string
    {
        [$group, $name] = explode('.', $key, 2);

        $value = DB::table('settings')->where('group', $group)->where('key', $name)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function settingsSnapshot(): array
    {
        return DB::table('settings')
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [$row->group.'.'.$row->key => (array) $row])
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $offenders
     */
    private function describe(array $offenders): string
    {
        $lines = [];

        foreach ($offenders as $key => $files) {
            $lines[] = '  '.$key.' — '.implode(', ', $files);
        }

        return implode("\n", $lines);
    }
}
