<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Activity;
use App\Models\Setting;
use App\Models\User;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Encryption": `mail.password` is unreadable in the raw DB row, decrypts correctly
 * through the repository, and never appears in the rendered HTML, the activity log — or anywhere a
 * failed save sends its input (the validation redirect, the flashed old input, the session store).
 *
 * Every assertion searches for the literal secret, so a leak through any path — a view echoing the
 * value, a log serialising it, an exception message quoting it — fails the test by name.
 */
final class SettingsSecretsTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const SECRET = 'Smtp-Only-Here-7f3a9c!';

    private const ROTATED = 'Rotated-Secret-2b8e41?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    #[Test]
    public function the_registry_marks_exactly_the_mail_password_as_encrypted(): void
    {
        $this->assertSame(['mail.password'], SettingsRegistry::encryptedKeys());
    }

    #[Test]
    public function the_password_is_ciphertext_at_rest_and_clear_text_through_the_repository(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveMailPasswordThroughTheScreen($admin, self::SECRET);

        $raw = $this->rawSetting('mail.password');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::SECRET, $raw, 'The raw column must never hold the password.');
        $this->assertSame(self::SECRET, Crypt::decryptString($raw), 'The column holds a real Laravel ciphertext of the password.');
        $this->assertTrue((bool) DB::table('settings')->where('group', 'mail')->where('key', 'password')->value('is_encrypted'));

        $this->assertSame(self::SECRET, $this->freshSetting('mail.password'), 'The repository hands the clear text back.');
        $this->assertSame(self::SECRET, Setting::query()->where('group', 'mail')->where('key', 'password')->firstOrFail()->value);

        $cached = Cache::get(SettingsRepository::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertStringNotContainsString(self::SECRET, json_encode($cached), 'The cached payload holds ciphertext, never the clear text.');
    }

    #[Test]
    public function the_password_never_reaches_a_rendered_page(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveMailPasswordThroughTheScreen($admin, self::SECRET);

        $mail = $this->actingAs($admin)->get('/admin/settings/mail')->assertOk();
        $mail->assertDontSee(self::SECRET, false);
        $mail->assertDontSee(rawurlencode(self::SECRET), false);
        $mail->assertSee(Setting::MASK, false);
        $mail->assertSee('stored, encrypted', false);

        foreach (array_keys(SettingsRegistry::groups()) as $group) {
            $this->actingAs($admin)->get('/admin/settings/'.$group)->assertOk()->assertDontSee(self::SECRET, false);
        }

        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee(self::SECRET, false);
        $this->actingAs($admin)->get('/admin/activity-log')->assertOk()->assertDontSee(self::SECRET, false);

        $entry = Activity::query()->where('module', 'settings')->where('properties->key', 'mail.password')->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/activity-log/'.$entry->getKey())
            ->assertOk()
            ->assertDontSee(self::SECRET, false);
    }

    #[Test]
    public function the_activity_log_records_the_secret_as_encrypted_and_never_its_value(): void
    {
        $admin = $this->createSuperAdmin();

        $since = $this->lastActivityId();

        $this->saveMailPasswordThroughTheScreen($admin, self::SECRET);
        $this->saveMailPasswordThroughTheScreen($admin, self::ROTATED);

        $rows = Activity::query()
            ->where('id', '>', $since)
            ->where('properties->key', 'mail.password')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows, 'Setting and then rotating the password is two changes of one key.');

        $this->assertNull($rows[0]->properties['old']['value'], 'Nothing was stored before the first save.');
        $this->assertSame(SettingsService::REDACTED, $rows[0]->properties['attributes']['value']);
        $this->assertSame(SettingsService::REDACTED, $rows[1]->properties['old']['value'], 'The old secret is as much a leak as the new one.');
        $this->assertSame(SettingsService::REDACTED, $rows[1]->properties['attributes']['value']);

        $everything = DB::table('activity_log')->where('id', '>', $since)->get()->toJson();

        foreach ([self::SECRET, self::ROTATED] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, 'No activity row may carry the password.');
        }

        $this->assertStringNotContainsString(
            (string) $this->rawSetting('mail.password'),
            $everything,
            'Not even the ciphertext belongs in the audit trail.'
        );
    }

    #[Test]
    public function a_failed_save_never_carries_the_password_back_to_the_browser_or_into_the_session(): void
    {
        $admin = $this->createSuperAdmin();

        $payload = $this->browserPayload($admin, 'mail', [
            'mailer' => 'smtp',
            'host' => 'smtp.example.test',
            'password' => self::SECRET,
            'from_address' => 'this is not an email address',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/settings/mail')
            ->put('/admin/settings/mail', $payload);

        $response->assertRedirect('/admin/settings/mail');
        $response->assertSessionHasErrors('settings.from_address');

        $this->assertNull($this->rawSetting('mail.password'), 'The refused save wrote nothing.');

        $errors = json_encode(session('errors')?->getBag('default')->toArray());
        $this->assertStringNotContainsString(self::SECRET, (string) $errors, 'No validation message may quote the password.');

        $this->assertStringNotContainsString(
            self::SECRET,
            (string) json_encode(session()->getOldInput()),
            'The flashed old input must not carry the SMTP password: it is written to the session store in clear text.'
        );

        $this->assertStringNotContainsString(
            self::SECRET,
            (string) json_encode(session()->all()),
            'Nothing in the session may hold the password.'
        );

        $this->actingAs($admin)
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertDontSee(self::SECRET, false);

        foreach (DB::table('sessions')->pluck('payload') as $stored) {
            $this->assertStringNotContainsString(self::SECRET, (string) base64_decode((string) $stored), 'The session store must never hold the password.');
        }
    }

    #[Test]
    public function a_json_validation_error_does_not_echo_the_password(): void
    {
        $admin = $this->createSuperAdmin();

        $payload = $this->browserPayload($admin, 'mail', [
            'password' => str_repeat('x', 191).self::SECRET,
        ]);

        $this->actingAs($admin)
            ->putJson('/admin/settings/mail', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.password')
            ->assertDontSee(self::SECRET, false);
    }

    #[Test]
    public function an_empty_or_masked_submission_leaves_the_stored_secret_untouched(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveMailPasswordThroughTheScreen($admin, self::SECRET);
        $ciphertext = $this->rawSetting('mail.password');

        foreach (['', Setting::MASK] as $submitted) {
            $since = $this->lastActivityId();

            $this->actingAs($admin)
                ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', ['password' => $submitted]))
                ->assertSessionHasNoErrors();

            $this->assertSame($ciphertext, $this->rawSetting('mail.password'), 'Submitting '.var_export($submitted, true).' must not touch the secret.');
            $this->assertSame(self::SECRET, $this->freshSetting('mail.password'));
            $this->assertCount(0, $this->settingsActivitySince($since));
        }
    }

    #[Test]
    public function the_rendered_password_field_is_disabled_until_changed_so_an_untouched_save_posts_no_secret(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveMailPasswordThroughTheScreen($admin, self::SECRET);

        $payload = $this->browserPayload($admin, 'mail');

        $this->assertArrayNotHasKey('password', $payload['settings'], 'The masked field is disabled, so a plain save sends no password key at all.');
    }

    /**
     * `php artisan config:cache` (and `optimize`, which runs it) boots a fresh application and
     * var_exports its whole configuration to disk. If booting copies the decrypted SMTP password into
     * `config('mail')`, the credential lands in plain text in `bootstrap/cache/config.php` — in every
     * deploy artefact and backup, and it survives a rotation in the UI.
     *
     * The real command is run in a child process, exactly as a deploy runs it, against the test schema
     * (never the dev database) and with `APP_CONFIG_CACHE` pointing at a throwaway file, so no cached
     * configuration is ever left behind. Because the child opens its own connection it cannot see this
     * test's open transaction: the ciphertext is committed through a side connection and restored in
     * `finally`.
     */
    #[Test]
    public function config_cache_never_writes_the_decrypted_password_to_disk(): void
    {
        $relative = 'storage/framework/testing/phase2-config-cache-'.bin2hex(random_bytes(6)).'.php';
        $absolute = base_path($relative);
        $bootstrapCache = base_path('bootstrap/cache/config.php');
        $bootstrapCacheExisted = is_file($bootstrapCache);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        config(['database.connections.phase2_side' => config('database.connections.'.config('database.default'))]);
        $side = DB::connection('phase2_side');

        $this->assertSame('my_office_test', $side->getDatabaseName(), 'The side connection must be the test schema.');

        $row = $side->table('settings')->where('group', 'mail')->where('key', 'password')->first();
        $this->assertNotNull($row, 'The seeded mail.password row exists.');

        try {
            $side->table('settings')->where('id', $row->id)->update([
                'value' => Crypt::encryptString(self::SECRET),
                'is_encrypted' => true,
            ]);

            $process = new Process(
                [PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'APP_CONFIG_CACHE' => $relative,
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => (string) config('database.connections.mysql.host'),
                    'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => 'my_office_test',
                    'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                    'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    'DB_URL' => '',
                    // Never read a settings payload the dev install cached on disk.
                    'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync',
                    'MAIL_MAILER' => 'array',
                ],
            );
            $process->setTimeout(120);
            $process->run();

            $this->assertTrue($process->isSuccessful(), 'config:cache failed: '.$process->getErrorOutput().$process->getOutput());
            $this->assertFileExists($absolute, 'config:cache wrote no file at the temporary path.');

            $cached = (string) file_get_contents($absolute);

            $this->assertStringStartsWith('<?php return', $cached);
            $this->assertStringContainsString('my_office_test', $cached, 'The cached configuration came from the child booted on the test schema.');
            $this->assertStringNotContainsString(
                self::SECRET,
                $cached,
                'APP BUG: config:cache wrote the decrypted SMTP password into the cached configuration file in plain text. '
                .'ConfigureFromSettings::apply() copies the secret into config(\'mail.mailers.smtp.password\') while the '
                .'console kernel boots; apply mail settings lazily (when the mail manager builds its transport) instead.',
            );
        } finally {
            $side->table('settings')->where('id', $row->id)->update([
                'value' => $row->value,
                'is_encrypted' => $row->is_encrypted,
            ]);

            if (is_file($absolute)) {
                unlink($absolute);
            }

            DB::purge('phase2_side');
        }

        if (! $bootstrapCacheExisted) {
            $this->assertFileDoesNotExist($bootstrapCache, 'The test must never leave a cached configuration behind.');
        }
    }

    private function saveMailPasswordThroughTheScreen(User $admin, string $password): void
    {
        // The browser posts the password only after "Change" enables the input: the override
        // stands in for that click.
        $payload = $this->browserPayload($admin, 'mail', [
            'mailer' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => '587',
            'username' => 'mailer@example.test',
            'password' => $password,
        ]);

        $this->actingAs($admin)
            ->from('/admin/settings/mail')
            ->put('/admin/settings/mail', $payload)
            ->assertRedirect('/admin/settings/mail')
            ->assertSessionHasNoErrors();
    }
}
