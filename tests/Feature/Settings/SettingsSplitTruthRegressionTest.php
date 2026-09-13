<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Support\ConfigureFromSettings;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * THE SPLIT-TRUTH REGRESSION (phase-02 §2 amendment).
 *
 * The defect: the Branding and Contact screens wrote the canonical keys (`branding.logo_light`,
 * `contact.support_email`, …) while the shell kept rendering the Phase 1 rows
 * (`company.logo_path`, `company.support_email`, …). Every save "worked" and nothing on any page
 * changed. `SettingsTruthTest` pins the static half (a grep of what the views read); this file pins
 * the behavioural half by doing what the administrator did and **asserting on the rendered HTML**.
 *
 * Each test plants the legacy row with a recognisable value first, so a view that ever slides
 * back to reading it fails by showing the wrong thing, not merely by missing the right one.
 */
final class SettingsSplitTruthRegressionTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();

        Storage::fake('public');
    }

    #[Test]
    public function uploading_the_light_logo_changes_the_logo_every_shell_renders(): void
    {
        $admin = $this->createSuperAdmin();

        $this->plantLegacy('company.logo_path', 'settings/legacy-logo-from-phase-one.png');
        $this->plantLegacy('company.favicon_path', 'settings/legacy-favicon-from-phase-one.png');

        $payload = $this->browserPayload($admin, 'branding');
        $payload['settings']['logo_light'] = UploadedFile::fake()->image('logo.png', 240, 64);

        $this->actingAs($admin)->put('/admin/settings/branding', $payload)->assertSessionHasNoErrors();

        $url = $this->publicUrl((string) $this->rawSetting('branding.logo_light'));

        $shell = $this->actingAs($admin)->get('/admin')->assertOk();
        $shell->assertSee('src="'.e($url).'"', false);
        $shell->assertSee('rel="icon" href="'.e($url).'"', false);
        $shell->assertDontSee('legacy-logo-from-phase-one', false);
        $shell->assertDontSee('legacy-favicon-from-phase-one', false);

        $this->signOut();

        $this->get('/login')
            ->assertOk()
            ->assertSee('src="'.e($url).'"', false)
            ->assertDontSee('legacy-logo-from-phase-one', false);
    }

    #[Test]
    public function uploading_the_dark_logo_and_favicon_changes_the_markup_that_uses_them(): void
    {
        $admin = $this->createSuperAdmin();

        $this->plantLegacy('company.logo_dark_path', 'settings/legacy-dark-logo.png');

        $payload = $this->browserPayload($admin, 'branding');
        $payload['settings']['logo_light'] = UploadedFile::fake()->image('light.png', 240, 64);
        $payload['settings']['logo_dark'] = UploadedFile::fake()->image('dark.png', 240, 64);
        $payload['settings']['favicon'] = UploadedFile::fake()->image('favicon.png', 64, 64);

        $this->actingAs($admin)->put('/admin/settings/branding', $payload)->assertSessionHasNoErrors();

        $light = $this->publicUrl((string) $this->rawSetting('branding.logo_light'));
        $dark = $this->publicUrl((string) $this->rawSetting('branding.logo_dark'));
        $favicon = $this->publicUrl((string) $this->rawSetting('branding.favicon'));

        $shell = $this->actingAs($admin)->get('/admin')->assertOk();
        $shell->assertSee('src="'.e($light).'"', false);
        $shell->assertSee('src="'.e($dark).'"', false);
        $shell->assertSee('rel="icon" href="'.e($favicon).'"', false);
        $shell->assertDontSee('legacy-dark-logo', false);

        $this->signOut();

        // The sign-in panel is brand-coloured in both themes, so it prefers the dark variant.
        $this->get('/login')->assertOk()->assertSee('src="'.e($dark).'"', false);
    }

    #[Test]
    public function saving_contact_details_changes_the_footer_every_page_renders(): void
    {
        $admin = $this->createSuperAdmin();

        $this->plantLegacy('company.support_email', 'legacy-support@phase-one.test');
        $this->plantLegacy('company.email', 'legacy-info@phase-one.test');
        $this->plantLegacy('company.phone', '+1 555 0100 legacy');

        $this->actingAs($admin)
            ->put('/admin/settings/contact', $this->browserPayload($admin, 'contact', [
                'support_email' => 'help@acme-learning.test',
                'email' => 'hello@acme-learning.test',
                'phone' => '+92 311 5550199',
            ]))
            ->assertSessionHasNoErrors();

        $page = $this->actingAs($admin)->get('/admin/settings/contact')->assertOk();
        $page->assertSee('mailto:help@acme-learning.test', false);
        $page->assertSee('+92 311 5550199', false);
        $page->assertDontSee('legacy-support@phase-one.test', false);
        $page->assertDontSee('legacy-info@phase-one.test', false);
        $page->assertDontSee('+1 555 0100 legacy', false);

        // Clearing the support address falls back to the contact address — also a canonical key.
        $this->actingAs($admin)
            ->put('/admin/settings/contact', $this->browserPayload($admin, 'contact', ['support_email' => '']))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('mailto:hello@acme-learning.test', false)
            ->assertDontSee('mailto:help@acme-learning.test', false);
    }

    #[Test]
    public function saving_the_company_identity_changes_the_shell_and_the_sign_in_page(): void
    {
        $admin = $this->createSuperAdmin();

        $this->plantLegacy('company.description', 'Legacy description nobody can edit');

        $this->actingAs($admin)
            ->put('/admin/settings/company', $this->browserPayload($admin, 'company', [
                'name' => 'Acme Learning Systems',
                'short_name' => 'AcmeLS',
                'website' => 'https://www.acme-learning.test',
                'tagline' => 'Ship software, grow engineers.',
                'features' => '["Invoices that reconcile themselves","Batches that never double-book"]',
            ]))
            ->assertSessionHasNoErrors();

        $shell = $this->actingAs($admin)->get('/admin')->assertOk();
        $shell->assertSee('AcmeLS', false);
        $shell->assertSee('· Acme Learning Systems</title>', false);
        $shell->assertSee('href="https://www.acme-learning.test"', false);

        $this->signOut();

        $this->get('/login')
            ->assertOk()
            ->assertSee('Acme Learning Systems', false)
            ->assertSee('Ship software, grow engineers.', false)
            ->assertSee('Invoices that reconcile themselves', false)
            ->assertSee('Batches that never double-book', false)
            ->assertDontSee('Legacy description nobody can edit', false);
    }

    #[Test]
    public function saving_the_brand_colour_repaints_every_shell(): void
    {
        $admin = $this->createSuperAdmin();

        $this->plantLegacy('appearance.brand_color', '#e11d48');

        $this->actingAs($admin)
            ->put('/admin/settings/branding', $this->browserPayload($admin, 'branding', ['brand_color' => '#059669']))
            ->assertSessionHasNoErrors();

        $palette = ConfigureFromSettings::brandPalette('#059669');
        $this->assertSame('5 150 105', $palette[600], 'The saved colour is the 600 stop, reproduced exactly.');

        $shell = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<style id="brand-theme">[^<]*--brand-600:5 150 105;/', $shell);
        $this->assertStringNotContainsString('225 29 72', $shell, 'The legacy appearance.brand_color row must never paint the shell.');

        $this->signOut();

        $this->assertMatchesRegularExpression(
            '/<style id="brand-theme">[^<]*--brand-600:5 150 105;/',
            (string) $this->get('/login')->assertOk()->getContent(),
        );
    }

    #[Test]
    public function saving_the_maintenance_message_changes_the_holding_page(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/maintenance', $this->browserPayload($admin, 'maintenance', [
                'maintenance_message' => 'Back at 18:00 PKT after the database upgrade.',
            ], check: ['maintenance_mode']))
            ->assertSessionHasNoErrors();

        $this->signOut();

        $this->get('/')->assertStatus(503)->assertSee('Back at 18:00 PKT after the database upgrade.', false);
    }

    /*
    |--------------------------------------------------------------------------
    | The static half: nothing that renders reads an undeclared key
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function no_view_route_or_middleware_reads_a_key_the_registry_does_not_declare(): void
    {
        $reads = $this->literalReads([
            resource_path('views'),
            base_path('routes'),
            app_path('Http'),
            app_path('Dashboard'),
        ]);

        $this->assertNotSame([], $reads, 'The scan found no setting reads at all — it has stopped looking in the right place.');

        $undeclared = array_filter(
            $reads,
            static fn (string $key): bool => ! SettingsRegistry::has($key),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame(
            [],
            $undeclared,
            "Rendering code reads keys the registry does not declare — a value nobody can edit, and the seed of a second truth:\n"
            .$this->describe($undeclared)
        );
    }

    #[Test]
    public function the_formatters_views_call_read_only_declared_keys(): void
    {
        // money(), app_date(), app_time(), app_datetime() and app_number() are how a view renders
        // an amount or a date, so every key those formatters consult is a key a view reads.
        $reads = $this->literalReads([
            app_path('Support/Format.php'),
            app_path('Support/Money.php'),
            app_path('Support/helpers.php'),
        ]);

        $this->assertArrayHasKey('localization.date_format', $reads, 'The scan must see what Format reads.');
        $this->assertArrayHasKey('localization.currency_symbol', $reads, 'The scan must see what Money reads.');

        $undeclared = array_filter(
            $reads,
            static fn (string $key): bool => ! SettingsRegistry::has($key),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame(
            [],
            $undeclared,
            "money() / app_date() consult keys the registry does not declare. Any row stored under one of these\n"
            ."silently overrides (or reshapes) what the Localization screen saves, and no screen can change it back —\n"
            ."the split-truth defect in the formatting layer. Either declare each key or stop reading it:\n"
            .$this->describe($undeclared)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Every literal `group.key` passed to setting() / settings_repo()->get() / the repository, or
     * listed in a `*_KEYS` constant, under the given paths.
     *
     * @param  list<string>  $paths
     * @return array<string, list<string>> key => files
     */
    private function literalReads(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $groups = implode('|', array_map('preg_quote', array_keys(SettingsRegistry::groups()) + ['general', 'website']));

        $patterns = [
            // setting('company.name') / setting("company.name")
            '/\bsetting\(\s*[\'"]([a-z_]+\.[a-z0-9_.]+)[\'"]/',
            // settings_repo()->get('mail.host'), $this->settings->get('company.name'), self::setting('localization.x', …)
            '/(?:settings_repo\(\)|->settings|self::|static::|\$repository|\$settings)\s*(?:->|::)\s*(?:get|setting|has)\(\s*[\'"]([a-z_]+\.[a-z0-9_.]+)[\'"]/',
            // const SYMBOL_KEYS = ['finance.currency_symbol', …] — only strings that start with a settings group
            '/[\'"]((?:'.$groups.'|finance|company|general)\.[a-z][a-z0-9_]*)[\'"]/',
        ];

        $reads = [];

        foreach ($files as $file) {
            $body = (string) file_get_contents($file);
            $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
            $isKeyList = str_ends_with($relative, 'Support/Money.php');

            foreach ($patterns as $index => $pattern) {
                // The bare-string pattern is only meaningful inside Money's key lists; anywhere else a
                // dotted string is as likely a route name or a translation key.
                if ($index === 2 && ! $isKeyList) {
                    continue;
                }

                if (preg_match_all($pattern, $body, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $key) {
                    if (substr_count($key, '.') !== 1) {
                        continue;
                    }

                    $reads[$key][] = $relative;
                    $reads[$key] = array_values(array_unique($reads[$key]));
                }
            }
        }

        ksort($reads);

        return $reads;
    }

    /**
     * @param  array<string, list<string>>  $reads
     */
    private function describe(array $reads): string
    {
        $lines = [];

        foreach ($reads as $key => $files) {
            $lines[] = '  '.$key.' — '.implode(', ', $files);
        }

        return implode("\n", $lines);
    }

    /**
     * Recreate a Phase 1 row that a relocation superseded, holding a value the page must never show.
     */
    private function plantLegacy(string $dotted, string $value): void
    {
        [$group, $key] = explode('.', $dotted, 2);

        DB::table('settings')->updateOrInsert(['group' => $group, 'key' => $key], [
            'value' => $value,
            'type' => 'string',
            'is_encrypted' => false,
            'is_public' => true,
            'is_readonly' => true,
            'label' => 'Deprecated - legacy row planted by the regression test',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SettingsRepository::class)->flush();
    }

    private function publicUrl(string $path): string
    {
        $this->assertNotSame('', $path, 'The upload did not store a path.');

        return Storage::disk('public')->url($path);
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
