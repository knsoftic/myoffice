<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Activity;
use App\Models\Setting;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;
use Throwable;

/**
 * Phase 2 closing pass — who may write a settings row, from where, and what can never happen to one.
 *
 *   · `SettingsService` fails closed without an actor; only `asSystem()`, asked for by name from a
 *     console process, writes without one — and a system copy stops working the moment the process is
 *     serving HTTP.
 *   · A row stored `is_readonly = 1` is locked even when the registry declares the key editable.
 *   · `SettingsRepository::set()` / `setMany()` refuse a registry-declared key and a stored-readonly row
 *     outside `asSystem()`, and a document counter (`*_next_number`, D62) in every context there is.
 *   · Nothing deletes a settings row.
 *   · A submitted value that happens to be valid Laravel ciphertext is stored as that literal string and
 *     is never decrypted — the model is no longer a decryption oracle.
 *   · A queued job is a console process, and still gets no exemption it did not ask for.
 *
 * The repository and the model exempt the test suite itself (`app()->runningUnitTests()`), which is
 * what lets every other test plant a fixture row. The guards those exemptions skip are exercised here
 * with the `env` binding switched away from `testing` for the duration of one closure
 * ({@see self::outsideTheTestSuite()}), so the production code path runs unchanged.
 */
final class SettingsWriteGuardsTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const PROBE_CACHE_KEY = 'phase2.settings-write-guards.queued-probe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | SettingsService: no actor, no write
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_service_refuses_every_write_that_has_no_actor(): void
    {
        $this->assertGuest();

        [$fileGroup, $fileKey] = explode('.', SettingsRegistry::fileKeys()[0], 2);
        $this->putRaw($fileGroup.'.'.$fileKey, 'settings/existing-logo.png');

        $service = app(SettingsService::class);

        $nameBefore = $this->rawSetting('company.name');
        $since = $this->lastActivityId();

        $attempts = [
            'update()' => fn () => $service->update('company', ['name' => 'Nobody Signed This']),
            'update() with an explicit null actor' => fn () => $service->update('company', ['name' => 'Nobody Signed This'], null),
            'resetGroup()' => fn () => $service->resetGroup('company'),
            'deleteFile()' => fn () => $service->deleteFile($fileGroup, $fileKey),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail(sprintf('SettingsService::%s wrote settings with no actor.', $label));
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFalse($service->canEditGroup('company'), 'With no user and no system context the answer is no.');
        $this->assertSame($nameBefore, $this->rawSetting('company.name'));
        $this->assertSame('settings/existing-logo.png', $this->rawSetting($fileGroup.'.'.$fileKey));
        $this->assertCount(0, $this->settingsActivitySince($since));
    }

    #[Test]
    public function as_system_from_the_console_writes_without_an_actor_and_leaves_the_original_instance_closed(): void
    {
        $this->assertTrue(app()->runningInConsole(), 'PHPUnit is a console process.');
        $this->assertGuest();

        $service = app(SettingsService::class);
        $system = $service->asSystem();

        $this->assertNotSame($service, $system, 'asSystem() hands back a copy.');

        $since = $this->lastActivityId();

        $changes = $system->update('company', ['name' => 'Written By The Console Ltd']);

        $this->assertArrayHasKey('company.name', $changes);
        $this->assertSame('Written By The Console Ltd', $this->rawSetting('company.name'));

        $row = Setting::query()->where('group', 'company')->where('key', 'name')->firstOrFail();
        $this->assertNull($row->updated_by, 'A system write is stamped by nobody.');

        $activity = $this->settingsActivitySince($since);
        $this->assertCount(1, $activity, 'A system write is still audited.');
        $this->assertNull($activity->first()->causer_id);

        // Asking for the system context on one instance never opens the instance it was asked on.
        try {
            $service->update('company', ['name' => 'Leaked Through The Original']);
            $this->fail('asSystem() lifted the guard on the original service instance.');
        } catch (AuthorizationException) {
            $this->assertSame('Written By The Console Ltd', $this->rawSetting('company.name'));
        }
    }

    #[Test]
    public function as_system_is_refused_over_http_and_a_system_copy_cannot_write_once_the_process_serves_http(): void
    {
        $system = app(SettingsService::class)->asSystem();

        $this->treatRequestsAsWeb();
        $this->assertFalse(app()->runningInConsole());

        try {
            app(SettingsService::class)->asSystem();
            $this->fail('SettingsService::asSystem() was granted outside the console.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $before = $this->rawSetting('company.name');

        try {
            $system->update('company', ['name' => 'Outlived Its Command']);
            $this->fail('A system copy made in the console wrote settings while the process was serving HTTP.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->rawSetting('company.name'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The stored is_readonly flag
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_row_stored_readonly_is_locked_even_though_the_registry_declares_it_editable(): void
    {
        $admin = $this->createSuperAdmin();

        $this->assertFalse(SettingsRegistry::field('company.name')['readonly'], 'Precondition: the registry says company.name is editable.');
        $this->assertNotSame(SettingsRegistry::field('company.name')['default'], 'Locked By A Migration Ltd');

        DB::table('settings')->where('group', 'company')->where('key', 'name')->update([
            'value' => 'Locked By A Migration Ltd',
            'is_readonly' => true,
        ]);
        app(SettingsRepository::class)->flush();

        $service = app(SettingsService::class);

        // The service refuses a different value…
        try {
            $service->update('company', ['name' => 'Changed Anyway', 'tagline' => 'Beside it'], $admin);
            $this->fail('SettingsService moved a row stored is_readonly.');
        } catch (ActionNotAllowedException) {
            $this->assertSame('Locked By A Migration Ltd', $this->rawSetting('company.name'));
            $this->assertNotSame('Beside it', $this->rawSetting('company.tagline'), 'A refused save writes nothing at all.');
        }

        // …drops the same value silently, saves the key beside it, and never clears the lock.
        $service->update('company', ['name' => 'Locked By A Migration Ltd', 'tagline' => 'Saved beside a locked key'], $admin);

        $this->assertSame('Saved beside a locked key', $this->rawSetting('company.tagline'));
        $this->assertTrue($this->storedReadonly('company.name'), 'A save must never clear a stored lock.');

        // The screen refuses it by name.
        $payload = $this->browserPayload($admin, 'company');
        $payload['settings']['name'] = 'Changed Through The Screen';

        $this->actingAs($admin)
            ->from('/admin/settings/company')
            ->put('/admin/settings/company', $payload)
            ->assertSessionHasErrors('settings.name');

        // A reset leaves it where it is.
        $service->resetGroup('company', $admin);

        $this->assertSame('Locked By A Migration Ltd', $this->rawSetting('company.name'));
        $this->assertTrue($this->storedReadonly('company.name'));
        $this->assertContains('name', $service->readonlyKeys('company'));
    }

    /*
    |--------------------------------------------------------------------------
    | SettingsRepository::set() / setMany()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function set_refuses_a_declared_key_outside_the_system_context_and_allows_it_inside(): void
    {
        $repository = settings_repo();
        $before = $this->rawSetting('company.name');

        $this->outsideTheTestSuite(function () use ($repository, $before): void {
            $this->assertRefused(fn () => $repository->set('company.name', 'Unaudited Console Write'), 'set() of a declared key without asSystem()');
            $this->assertSame($before, $this->rawSetting('company.name'));

            // setMany() checks every key before writing any of them.
            $this->assertRefused(
                fn () => $repository->setMany(['zz_guard.harmless' => 'written first?', 'company.name' => 'Unaudited']),
                'setMany() carrying a declared key',
            );
            $this->assertNull($this->rawSetting('zz_guard.harmless'), 'A refused setMany() must not leave the keys before the refused one written.');

            // The guard is specific: an undeclared, unlocked key is a plain low-level write.
            $repository->set('zz_guard.scratch', 'allowed');
            $this->assertSame('allowed', $this->rawSetting('zz_guard.scratch'));

            // Inside the explicit system context, from the console, the declared key may move.
            $repository->asSystem(fn (SettingsRepository $settings) => $settings->set('company.name', 'Console Wrote This Ltd'));
            $this->assertSame('Console Wrote This Ltd', $this->rawSetting('company.name'));

            // The context closes again when the callback returns — and when it throws.
            $this->assertRefused(fn () => $repository->set('company.name', 'After The Block'), 'set() after asSystem() returned');

            try {
                $repository->asSystem(static function (): never {
                    throw new RuntimeException('the command failed half way');
                });
            } catch (RuntimeException) {
                // expected
            }

            $this->assertRefused(fn () => $repository->set('company.name', 'After A Failed Block'), 'set() after asSystem() threw');
            $this->assertSame('Console Wrote This Ltd', $this->rawSetting('company.name'));
        });
    }

    #[Test]
    public function set_refuses_a_readonly_key_outside_the_system_context(): void
    {
        $repository = settings_repo();

        DB::table('settings')->insert([
            'group' => 'zz_guard',
            'key' => 'reserved_by_migration',
            'value' => 'reserved',
            'type' => 'string',
            'is_encrypted' => false,
            'is_public' => false,
            'is_readonly' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repository->flush();

        $this->assertFalse(SettingsRegistry::has('zz_guard.reserved_by_migration'), 'Precondition: the row is locked by its stored flag alone.');

        $this->outsideTheTestSuite(function () use ($repository): void {
            $this->assertRefused(fn () => $repository->set('zz_guard.reserved_by_migration', 'moved'), 'set() of a stored-readonly row');
            $this->assertSame('reserved', $this->rawSetting('zz_guard.reserved_by_migration'));

            // A key the registry declares readonly is refused as well (it is declared).
            $this->assertRefused(fn () => $repository->set('security.two_factor_enabled', true), 'set() of a registry-readonly key');
            $this->assertSame('0', $this->rawSetting('security.two_factor_enabled'));

            $repository->asSystem(fn (SettingsRepository $settings) => $settings->set('zz_guard.reserved_by_migration', 'moved by the console'));
            $this->assertSame('moved by the console', $this->rawSetting('zz_guard.reserved_by_migration'));
        });
    }

    #[Test]
    public function a_document_counter_is_refused_in_every_context_including_the_system_context(): void
    {
        $repository = settings_repo();

        $this->putRaw('finance.invoice_next_number', '43');

        $counters = ['finance.invoice_next_number', 'zz_guard.receipt_next_number'];

        $contexts = [
            'the test suite' => static fn (Closure $write) => $write(),
            'a console process outside asSystem()' => fn (Closure $write) => $this->outsideTheTestSuite($write),
            'asSystem() in the console' => fn (Closure $write) => $this->outsideTheTestSuite(
                static fn () => $repository->asSystem(static fn () => $write()),
            ),
            'asSystem() inside the test suite' => static fn (Closure $write) => $repository->asSystem(static fn () => $write()),
        ];

        foreach ($contexts as $context => $run) {
            foreach ($counters as $counter) {
                $this->assertRefused(
                    fn () => $run(static fn () => $repository->set($counter, 1)),
                    sprintf('set(%s) in %s', $counter, $context),
                );

                $this->assertRefused(
                    fn () => $run(static fn () => $repository->setMany(['zz_guard.beside_the_counter' => 'x', $counter => 1])),
                    sprintf('setMany() carrying %s in %s', $counter, $context),
                );
            }
        }

        $this->assertSame('43', $this->rawSetting('finance.invoice_next_number'), 'The counter never moved.');
        $this->assertNull($this->rawSetting('zz_guard.receipt_next_number'), 'An undeclared counter is never created either.');
        $this->assertNull($this->rawSetting('zz_guard.beside_the_counter'), 'A setMany() refused for its counter writes nothing.');
    }

    #[Test]
    public function the_repository_system_context_is_refused_outside_the_console(): void
    {
        $this->treatRequestsAsWeb();

        $ran = false;

        $this->outsideTheTestSuite(function () use (&$ran): void {
            $this->assertRefused(
                function () use (&$ran): void {
                    settings_repo()->asSystem(function (SettingsRepository $settings) use (&$ran): void {
                        $ran = true;
                        $settings->set('company.name', 'Written Over HTTP');
                    });
                },
                'SettingsRepository::asSystem() outside the console',
            );
        });

        $this->assertFalse($ran, 'The callback must never run.');
        $this->assertNotSame('Written Over HTTP', $this->rawSetting('company.name'));
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing deletes a settings row
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function no_runtime_path_deletes_a_settings_row(): void
    {
        $admin = $this->createSuperAdmin();
        $repository = settings_repo();

        Storage::fake('public');
        Storage::fake('local');

        $count = DB::table('settings')->count();

        DB::table('settings')->insert([
            'group' => 'zz_guard',
            'key' => 'fixture',
            'value' => 'x',
            'type' => 'string',
            'is_encrypted' => false,
            'is_public' => false,
            'is_readonly' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repository->flush();
        $count++;

        $this->outsideTheTestSuite(function () use ($repository): void {
            $row = Setting::query()->where('group', 'company')->where('key', 'name')->firstOrFail();
            $this->assertRefused(fn () => $row->delete(), 'Setting::delete()');

            $this->assertRefused(fn () => $repository->forget('zz_guard.fixture'), 'forget() of an undeclared row outside the test suite');
            $this->assertRefused(
                fn () => $repository->asSystem(static fn (SettingsRepository $settings) => $settings->forget('zz_guard.fixture')),
                'forget() inside asSystem()',
            );
        });

        // Even the test-suite exemption never reaches a declared key or a counter.
        $this->assertRefused(fn () => $repository->forget('company.name'), 'forget() of a declared key');
        $this->assertRefused(fn () => $repository->forget('finance.invoice_next_number'), 'forget() of a counter');
        $this->assertRefused(fn () => $repository->forget('zz_guard.custom_next_number'), 'forget() of an undeclared counter');

        $this->assertSame($count, DB::table('settings')->count());

        // The service moves values; it never removes the row that holds them.
        $service = app(SettingsService::class);

        foreach (array_keys(SettingsRegistry::groups()) as $group) {
            $service->resetGroup($group, $admin);
        }

        [$fileGroup, $fileKey] = explode('.', SettingsRegistry::fileKeys()[0], 2);
        $this->putRaw($fileGroup.'.'.$fileKey, 'settings/logo-to-remove.png');
        Storage::disk(SettingsService::diskFor($fileGroup.'.'.$fileKey))->put('settings/logo-to-remove.png', 'png');

        $this->assertTrue($service->deleteFile($fileGroup, $fileKey, $admin));

        $this->assertNull($this->rawSetting($fileGroup.'.'.$fileKey), 'The file is gone from the row…');
        $this->assertTrue(
            DB::table('settings')->where('group', $fileGroup)->where('key', $fileKey)->exists(),
            '…but the row itself stays.',
        );
        $this->assertSame($count, DB::table('settings')->count(), 'Resetting every group and removing a file deleted no row.');
    }

    /**
     * The runtime guards above cover the model and the repository. This scan covers everything else:
     * no application class, seeder or migration issues a DELETE or TRUNCATE against `settings`, through
     * the query builder, the model or raw SQL. The single sanctioned statement is the test-suite-only
     * fixture removal inside `SettingsRepository::forget()`, which the test above proves is refused
     * outside the suite.
     */
    #[Test]
    public function no_source_file_issues_a_delete_against_the_settings_table(): void
    {
        $patterns = [
            'query builder' => '/DB::table\(\s*[\'"]settings[\'"]\s*\)[^;]*?->\s*(?:delete|truncate)\s*\(/s',
            'repository table constant' => '/DB::table\(\s*self::TABLE\s*\)[^;]*?->\s*(?:delete|truncate)\s*\(/s',
            'model query' => '/\bSetting::[^;]*?->\s*(?:delete|forceDelete)\s*\(/s',
            'model static' => '/\bSetting::(?:destroy|truncate)\s*\(/',
            'raw SQL' => '/\b(?:DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+`?settings`?\b/i',
        ];

        $offenders = [];

        foreach ([app_path(), database_path()] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                /** @var SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                foreach ($patterns as $label => $pattern) {
                    if ($label === 'repository table constant' && $relative !== 'app/Support/SettingsRepository.php') {
                        continue;
                    }

                    $matches = preg_match_all($pattern, $source);

                    if ($matches > 0) {
                        $offenders[$relative.' ('.$label.')'] = $matches;
                    }
                }
            }
        }

        $this->assertSame(
            ['app/Support/SettingsRepository.php (repository table constant)' => 1],
            $offenders,
            'Only SettingsRepository::forget() may carry a DELETE on settings, and it refuses outside the test suite.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The decryption oracle is closed
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_value_submitted_as_ciphertext_is_stored_as_that_literal_string_and_never_decrypted(): void
    {
        $admin = $this->createSuperAdmin();

        $plaintext = 'Decrypted-By-The-Oracle-5d1e';

        $shapes = [
            'encryptString()' => [Crypt::encryptString($plaintext), static fn (string $payload): string => Crypt::decryptString($payload)],
            'encrypt()' => [Crypt::encrypt($plaintext), static fn (string $payload): mixed => Crypt::decrypt($payload)],
        ];

        foreach ($shapes as $shape => [$ciphertext, $decrypt]) {
            $this->assertSame($plaintext, $decrypt($ciphertext), 'Precondition: '.$shape.' produced a real ciphertext for this application key.');

            $since = $this->lastActivityId();

            $this->actingAs($admin)
                ->from('/admin/settings/contact')
                ->put('/admin/settings/contact', $this->browserPayload($admin, 'contact', ['address' => $ciphertext]))
                ->assertSessionHasNoErrors();

            $this->assertSame($ciphertext, $this->rawSetting('contact.address'), $shape.': stored exactly as submitted.');
            $this->assertSame($ciphertext, $this->freshSetting('contact.address'), $shape.': the repository hands the literal back.');
            $this->assertSame(
                $ciphertext,
                Setting::query()->where('group', 'contact')->where('key', 'address')->firstOrFail()->value,
                $shape.': the model hands the literal back.',
            );

            $this->actingAs($admin)
                ->get('/admin/settings/contact')
                ->assertOk()
                ->assertDontSee($plaintext, false);

            $entry = $this->settingsActivitySince($since)->first();
            $this->assertInstanceOf(Activity::class, $entry);
            $this->assertSame($ciphertext, $entry->properties['attributes']['value'], $shape.': the audit records the literal.');
            $this->assertStringNotContainsString($plaintext, DB::table('activity_log')->where('id', '>', $since)->get()->toJson());
        }
    }

    #[Test]
    public function the_model_never_decrypts_a_ciphertext_it_was_handed(): void
    {
        $foreign = Crypt::encryptString('Another-Column-Secret-8c2f');

        // A plain row: assigned, saved, reloaded — the literal every time.
        $plain = new Setting(['group' => 'zz_oracle', 'key' => 'plain', 'value' => $foreign, 'type' => 'string', 'is_encrypted' => false]);
        $this->assertSame($foreign, $plain->value, 'Before save.');
        $plain->save();
        $this->assertSame($foreign, $this->rawSetting('zz_oracle.plain'));
        $this->assertSame($foreign, $plain->fresh()->value, 'After reload.');

        // An encrypted row: the submitted ciphertext is encrypted AGAIN, so reading it back returns the
        // ciphertext that was submitted — never what it decrypts to.
        $secret = new Setting(['group' => 'zz_oracle', 'key' => 'secret', 'value' => $foreign, 'type' => 'string', 'is_encrypted' => true]);
        $this->assertSame($foreign, $secret->value, 'Before save.');
        $secret->save();

        $raw = (string) $this->rawSetting('zz_oracle.secret');
        $this->assertNotSame($foreign, $raw, 'The column holds a fresh ciphertext of the submitted string.');
        $this->assertSame($foreign, Crypt::decryptString($raw));
        $this->assertSame($foreign, $secret->fresh()->value);
        $this->assertSame($foreign, $this->freshSetting('zz_oracle.secret'));

        // A loaded encrypted row whose value is replaced by a ciphertext: the literal, before and after save.
        $loaded = Setting::query()->where('group', 'zz_oracle')->where('key', 'secret')->firstOrFail();
        $other = Crypt::encryptString('Yet-Another-Secret-19ab');
        $loaded->value = $other;
        $this->assertSame($other, $loaded->value);
        $loaded->save();
        $this->assertSame($other, $loaded->fresh()->value);

        $this->assertNotContains('Another-Column-Secret-8c2f', [$plain->fresh()->value, $secret->fresh()->value, $loaded->fresh()->value]);
        $this->assertNotContains('Yet-Another-Secret-19ab', [$loaded->fresh()->value, $this->freshSetting('zz_oracle.secret')]);
    }

    /*
    |--------------------------------------------------------------------------
    | A queued job gets no exemption it did not ask for
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_queued_job_is_a_console_process_and_still_gets_no_system_exemption(): void
    {
        $this->assertGuest();

        $nameBefore = $this->rawSetting('company.name');

        $this->outsideTheTestSuite(function (): void {
            dispatch(static function (): void {
                $outcome = ['console' => app()->runningInConsole(), 'queued' => true];

                try {
                    settings_repo()->set('company.name', 'Queued Unaudited Write');
                    $outcome['repository'] = 'written';
                } catch (LogicException) {
                    $outcome['repository'] = 'refused';
                }

                try {
                    app(SettingsService::class)->update('company', ['name' => 'Queued Service Write']);
                    $outcome['service'] = 'written';
                } catch (AuthorizationException) {
                    $outcome['service'] = 'refused';
                }

                Cache::put(self::PROBE_CACHE_KEY, $outcome);
            });
        });

        $outcome = Cache::pull(self::PROBE_CACHE_KEY);

        $this->assertIsArray($outcome, 'The queued job never ran.');
        $this->assertTrue($outcome['console'], 'The job ran as a console process — the case the old exemption waved through.');
        $this->assertSame('refused', $outcome['repository'], 'A queued job wrote a declared key through the repository without asking for asSystem().');
        $this->assertSame('refused', $outcome['service'], 'A queued job wrote settings through the service with no actor.');
        $this->assertSame($nameBefore, $this->rawSetting('company.name'));
    }

    #[Test]
    public function a_job_run_inside_a_web_request_cannot_ask_for_the_system_context(): void
    {
        $this->treatRequestsAsWeb();

        $nameBefore = $this->rawSetting('company.name');

        $this->outsideTheTestSuite(function (): void {
            dispatch(static function (): void {
                $outcome = [];

                try {
                    settings_repo()->asSystem(static fn (SettingsRepository $settings) => $settings->set('company.name', 'Sync Job In A Request'));
                    $outcome['repository'] = 'written';
                } catch (LogicException) {
                    $outcome['repository'] = 'refused';
                }

                try {
                    app(SettingsService::class)->asSystem()->update('company', ['name' => 'Sync Job In A Request']);
                    $outcome['service'] = 'written';
                } catch (AuthorizationException) {
                    $outcome['service'] = 'refused';
                }

                Cache::put(self::PROBE_CACHE_KEY, $outcome);
            });
        });

        $outcome = Cache::pull(self::PROBE_CACHE_KEY);

        $this->assertIsArray($outcome, 'The job never ran.');
        $this->assertSame(['repository' => 'refused', 'service' => 'refused'], $outcome);
        $this->assertSame($nameBefore, $this->rawSetting('company.name'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Run `$callback` with the application no longer reporting itself as the test suite, so the
     * repository's and the model's test-suite exemptions are off and their production guards run.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function outsideTheTestSuite(callable $callback): mixed
    {
        $this->app->instance('env', 'local');

        try {
            $this->assertFalse(app()->runningUnitTests(), 'The test-suite exemption must be off inside this block.');

            return $callback();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function assertRefused(callable $attempt, string $what): void
    {
        try {
            $attempt();
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        } catch (Throwable $unexpected) {
            $this->fail(sprintf('%s failed with %s instead of being refused: %s', $what, $unexpected::class, $unexpected->getMessage()));
        }

        $this->fail($what.' was allowed.');
    }

    private function putRaw(string $dotted, string $value): void
    {
        [$group, $key] = explode('.', $dotted, 2);

        $this->assertSame(1, DB::table('settings')->where('group', $group)->where('key', $key)->update(['value' => $value]));

        app(SettingsRepository::class)->flush();
    }

    private function storedReadonly(string $dotted): bool
    {
        [$group, $key] = explode('.', $dotted, 2);

        return (bool) DB::table('settings')->where('group', $group)->where('key', $key)->value('is_readonly');
    }
}
