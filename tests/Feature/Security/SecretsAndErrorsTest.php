<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Logging\RedactSensitive;
use App\Support\Ops\SecurityAuditor;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Throwable;

use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * What the system must never say, and how it says nothing when something breaks
 * (phase-24-25 section 11.2, SEC-31, SEC-34..SEC-40).
 *
 * **A secret leaks through four doors and this class watches all four**: a rendered page, a URL, a
 * log line, and an error. The first three are the ones people remember. The fourth is the one that
 * actually happens - `APP_DEBUG=true` on a server somebody stood up in a hurry, and the next
 * uncaught exception prints the database password, the query that failed, the absolute path of the
 * file and the full environment, to whoever provoked it.
 *
 * **A URL is the least private thing in a request.** It is in the browser history, the `Referer`
 * header of every outbound link on the page, the proxy log, the web-server access log and the
 * analytics payload - none of which anybody thinks of as a place secrets are kept. So SEC-34 is not
 * about encryption: a token that is *correctly* encrypted is still wrong in a query string.
 *
 * **An error page is a security surface.** The framework's own is a beautiful, detailed map of the
 * application; a branded one that leaks nothing is not decoration, it is the control. And it has to
 * render with the database down, which is why `errors/layout.blade.php` reads no settings it cannot
 * wrap and loads no compiled bundle.
 */
#[Group('security')]
final class SecretsAndErrorsTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Words that must never name a path or query parameter (SEC-34).
     *
     * Compared **whole**, word by word, against the parameter name split on non-alphanumerics -
     * see `test_no_sensitive_data_in_urls()` for why a substring rule was the wrong shape.
     */
    private const FORBIDDEN_PARAMETERS = ['password', 'email', 'cnic', 'account', 'secret', 'dsn'];

    /** Paths that must not be served, whatever the web server is configured to do (SEC-39). */
    private const MUST_NOT_SERVE = [
        '/.env', '/.env.example', '/.git/config', '/composer.json', '/composer.lock',
        '/package.json', '/vendor/autoload.php', '/storage/logs/laravel.log', '/database/',
        '/docs/requirements.md', '/tests/',
    ];

    /** The six branded error views (GL-49). */
    private const ERROR_VIEWS = ['403', '404', '419', '429', '500', '503'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-31, SEC-34 - what a link may carry
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-31. A signature is a key with an expiry, and it is not transferable.
     *
     * **A signed URL is a bearer token in a link**, which is why the two things that can go wrong
     * are so ordinary: it gets forwarded, and it never expires. Laravel's signature covers the
     * whole URL including the expiry, so tampering with either invalidates it - and this asserts
     * that the application actually *checks* it rather than merely generating it.
     */
    #[Test]
    public function test_signed_urls_cannot_be_replayed_or_shared(): void
    {
        $signed = array_values(array_filter(
            require base_path('tests/Support/route-guard-manifest.php'),
            static fn (array $row): bool => in_array('signed', (array) ($row['middleware'] ?? []), true),
        ));

        $this->assertNotEmpty($signed, 'SEC-31: no route carries the `signed` middleware, so nothing here is asserted.');

        $checked = 0;

        foreach ($signed as $row) {
            $route = Route::getRoutes()->getByName((string) $row['route']);

            if ($route === null || $route->parameterNames() !== []) {
                // A bound parameter needs a real row; the unbound ones are enough to prove the
                // signature is checked, and section 11.4 owns the per-tenant half.
                continue;
            }

            $name = (string) $row['route'];

            // Expired: the signature is valid, the expiry is not.
            $expired = URL::temporarySignedRoute($name, now()->subMinute(), [], false);
            $this->get($expired)->assertForbidden();

            // Tampered: one character of the signature changed.
            $valid = URL::temporarySignedRoute($name, now()->addMinutes(10), [], false);
            $this->get($valid.'x')->assertForbidden();

            $checked++;
        }

        if ($checked === 0) {
            $this->markTestSkipped('Every `signed` route takes a bound parameter; asserting them needs the tenant fixture builder of section 11.4.');
        }
    }

    /**
     * SEC-34. No secret is ever a path segment or a query parameter.
     *
     * A search term is fine and is deliberately excluded: `?q=` in a log is a product decision, not
     * a leak. The health token is the sharp case - it is the one credential a monitor has to send on
     * every request, which is exactly why it must be a header or a signature rather than the query
     * string that ends up in the access log of every hop between here and the monitor.
     *
     * **Matched by word, and cleared by `whereNumber`.** The forbidden list used to be applied with
     * `str_contains`, which is how `payout-accounts/{account}/verify` became a finding: the
     * parameter is a route-model-bound row id constrained to `[0-9]+`, and "3" in a log is not a
     * bank account - it is the same number that is already in `/clients/3` on the line above. So the
     * name is split into words and each word is compared whole (which still catches
     * `{account_number}` and `{user_email}`, where a substring rule and a whole-string rule would
     * disagree), and a parameter the route constrains to digits is not a credential whatever it is
     * called. **A secret cannot be an integer**: it is the unguessability that makes it a secret,
     * and an id that a tenant may not read is SEC-32's problem, not this one's.
     */
    #[Test]
    public function test_no_sensitive_data_in_urls(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            foreach ($route->parameterNames() as $parameter) {
                // `[0-9]+` is what `->whereNumber()` compiles to: a bound id, never a credential.
                if (($route->wheres[$parameter] ?? null) === '[0-9]+') {
                    continue;
                }

                $words = preg_split('/[^a-z0-9]+/', strtolower($parameter)) ?: [];

                foreach (self::FORBIDDEN_PARAMETERS as $forbidden) {
                    if (in_array($forbidden, $words, true)) {
                        $offenders[] = $uri.' has {'.$parameter.'}';
                    }
                }
            }

            // `token` is allowed only as a signed-URL signature or a password-reset link, both of
            // which are single-use and expiring; anything else named token is a standing credential.
            foreach ($route->parameterNames() as $parameter) {
                if (str_contains(strtolower($parameter), 'token')
                    && ! str_starts_with((string) $route->getName(), 'password.')
                    && ! in_array('signed', $route->gatherMiddleware(), true)) {
                    $offenders[] = $uri.' has {'.$parameter.'} with no signature';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'SEC-34: '.implode(', ', array_unique($offenders)));

        // The health token is a header or a signature, never a query string.
        $health = Route::getRoutes()->getByName('ops.health');

        if ($health !== null) {
            $this->assertStringNotContainsString('token', $health->uri(), 'SEC-34: the health token is in the path.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-35, SEC-36, SEC-40 - what a response and a log may say
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-35. No screen prints a secret, and no log line keeps one.
     *
     * The crawl is as a **Super Admin**, deliberately: the person who may see everything is the
     * person whose pages would print everything if a view ever echoed a raw settings value. If the
     * most privileged session cannot find the application key in the HTML, no session can.
     */
    #[Test]
    public function test_secrets_never_reach_a_response_or_a_log(): void
    {
        $needles = array_values(array_filter([
            (string) config('app.key'),
            (string) config('database.connections.'.config('database.default').'.password'),
            (string) setting('mail.password', ''),
            (string) setting('backup.archive_password', ''),
            (string) setting('ops.health_check_token', ''),
            (string) setting('ops.maintenance_secret', ''),
        ], static fn (string $one): bool => strlen($one) >= 8));

        $this->assertNotEmpty($needles, 'SEC-35: no secret was long enough to search for - the fixture has none set.');

        $admin = $this->createSuperAdmin();
        $crawled = 0;

        foreach ($this->screens() as $name => $uri) {
            $response = $this->actingAs($admin)->get($uri);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $body = (string) $response->getContent();
            $crawled++;

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $body, sprintf('SEC-35: %s printed a secret.', $name));
            }

            // An absolute filesystem path above public/ tells a reader the deployment layout and,
            // on a shared host, the account name.
            $this->assertStringNotContainsString(base_path().DIRECTORY_SEPARATOR.'app', $body, sprintf('SEC-35: %s printed an absolute path.', $name));
        }

        $this->assertGreaterThan(0, $crawled, 'SEC-35: no screen answered 200, so nothing was searched.');

        // The same over a log line. RedactSensitive taps every channel, so a secret that reaches a
        // logger by any route - context array, exception message, request dump - is masked. Asked
        // of the key pattern rather than by writing a log line, because the tap is installed at
        // channel-creation time and a test channel would not carry it.
        $this->assertMatchesRegularExpression(
            RedactSensitive::KEY_PATTERN,
            'archive_password',
            'SEC-35: RedactSensitive would not recognise archive_password as a secret key.',
        );

        foreach (['password', 'api_token', 'authorization', 'health_check_token', 'error_monitoring_dsn'] as $key) {
            $this->assertMatchesRegularExpression(
                RedactSensitive::KEY_PATTERN,
                $key,
                sprintf('SEC-35: RedactSensitive would log %s in the clear.', $key),
            );
        }
    }

    /**
     * SEC-36. With debug off, an exception renders a page that says nothing and a log that says everything.
     *
     * **Both halves matter and they pull in opposite directions.** A response that leaks the trace
     * is a map of the application; a log that does not keep it is an outage nobody can diagnose.
     * The split is the design: the visitor gets a sentence, the operator gets the stack.
     */
    #[Test]
    public function test_debug_mode_leaks_nothing(): void
    {
        // Forced off for this request rather than asserted of the environment: the suite runs with
        // whatever APP_DEBUG the developer's .env carries, and what is being tested is the renderer,
        // not the .env. The handler reads the config at render time, so this is the real path.
        config(['app.debug' => false]);

        // `security:audit` is the half of SEC-36 that watches the server rather than the code:
        // APP_DEBUG on in a production-like environment is a finding there, not here.
        $this->assertIsArray((new SecurityAuditor)->auditEnvironment());

        Route::get('/__sec36_boom', static function (): void {
            throw new \RuntimeException('Secret detail: the database password is hunter2.');
        })->middleware('web');

        $response = $this->get('/__sec36_boom');

        $this->assertSame(500, $response->getStatusCode());

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('hunter2', $body, 'SEC-36: the exception message reached the response.');
        $this->assertStringNotContainsString('RuntimeException', $body, 'SEC-36: the exception class reached the response.');
        $this->assertStringNotContainsString('vendor'.DIRECTORY_SEPARATOR.'laravel', $body, 'SEC-36: a vendor path reached the response.');
        $this->assertStringNotContainsString(base_path(), $body, 'SEC-36: an absolute path reached the response.');
        $this->assertStringContainsString('Error 500', $body, 'SEC-36: the branded 500 page did not render.');
    }

    /**
     * SEC-40. The SMTP password is encrypted at rest, decrypts through the repository, and is masked everywhere else.
     *
     * **A mail password is somebody else's credential**, usually reused, and a settings form that
     * echoes it back into a `value=""` attribute publishes it to anybody who can read the page - or
     * the browser's saved form data, or a screen share.
     */
    #[Test]
    public function test_mail_and_smtp_credentials_are_protected(): void
    {
        $encrypted = SettingsRegistry::encryptedKeys();

        $this->assertContains('mail.password', $encrypted, 'SEC-40: mail.password is not declared encrypted.');
        $this->assertContains('backup.archive_password', $encrypted, 'SEC-40: backup.archive_password is not declared encrypted.');
        $this->assertContains('ops.health_check_token', $encrypted, 'SEC-40: ops.health_check_token is not declared encrypted.');
        $this->assertContains('ops.maintenance_secret', $encrypted, 'SEC-40: ops.maintenance_secret is not declared encrypted.');

        settings_repo()->set('mail.password', 'sup3r-secret-smtp');
        settings_repo()->flush();

        $raw = DB::table('settings')->where('group', 'mail')->where('key', 'password')->value('value');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('sup3r-secret-smtp', $raw, 'SEC-40: the raw settings row holds the password in the clear.');
        $this->assertSame('sup3r-secret-smtp', setting('mail.password'), 'SEC-40: the repository cannot decrypt what it wrote.');

        $admin = $this->createSuperAdmin();

        if (Route::getRoutes()->getByName('admin.settings.index') !== null) {
            $response = $this->actingAs($admin)->get(route('admin.settings.index', ['group' => 'mail'], false));

            if ($response->getStatusCode() === 200) {
                $this->assertStringNotContainsString('sup3r-secret-smtp', (string) $response->getContent(), 'SEC-40: the settings form echoed the password back.');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-37, SEC-38, SEC-39 - the pages, the advisories, the files
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-37. Six branded error pages that keep their layout and say nothing.
     *
     * The layout test is not cosmetic. `errors/layout.blade.php` inlines its CSS precisely so that
     * a deploy which has not finished building still renders a readable page - an error page that
     * depends on a Vite manifest is an error page that is blank during exactly the incident it was
     * written for.
     */
    #[Test]
    public function test_error_pages_are_branded_and_silent(): void
    {
        foreach (self::ERROR_VIEWS as $code) {
            $view = 'errors.'.$code;

            $this->assertTrue(View::exists($view), sprintf('SEC-37: %s does not exist.', $view));

            $html = View::make($view, [
                'exception' => null,
                'message' => 'Too many requests. Please try again in 1 minute.',
                'retryAfterSeconds' => 60,
                'retryAfterMinutes' => 1,
            ])->render();

            $this->assertStringContainsString('Error '.$code, $html, sprintf('SEC-37: %s does not name its status.', $view));

            // Branding, not framework branding.
            $this->assertStringNotContainsString('Laravel', $html, sprintf('SEC-37: %s names the framework.', $view));
            $this->assertStringNotContainsString('Whoops', $html, sprintf('SEC-37: %s carries framework copy.', $view));
            $this->assertStringNotContainsString('#0 ', $html, sprintf('SEC-37: %s carries a stack trace.', $view));
            $this->assertStringNotContainsString(base_path(), $html, sprintf('SEC-37: %s prints an absolute path.', $view));

            // The layout is self-contained: no build manifest, no external stylesheet.
            $this->assertStringContainsString('<style>', $html, sprintf('SEC-37: %s has no inline CSS - it depends on a build.', $view));
            $this->assertStringContainsString('prefers-color-scheme: dark', $html, sprintf('SEC-37: %s has no dark theme.', $view));
            $this->assertStringContainsString('noindex', $html, sprintf('SEC-37: %s is indexable.', $view));

            // An inline handler cannot carry a nonce, so under the CSP it renders a dead button.
            $this->assertStringNotContainsString('onclick=', $html, sprintf('SEC-37: %s uses an inline handler the CSP will refuse.', $view));
        }

        $expired = View::make('errors.419', ['exception' => null])->render();

        $this->assertStringContainsString('Refresh and try again', $expired, 'SEC-37: the 419 page offers no way to recover.');
    }

    /**
     * SEC-38. No high or critical advisory in the dependency tree.
     *
     * **This is the test whose result changes without the code changing**, which is why the
     * contract sends its output into `integrity_check_runs`: an advisory published overnight is a
     * vulnerability introduced overnight, and nothing in the diff will show it.
     */
    #[Test]
    public function test_dependency_advisories(): void
    {
        $process = new Process(['composer', 'audit', '--format=json', '--no-interaction'], base_path());
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (Throwable $exception) {
            $this->markTestSkipped('composer is not runnable from the test environment: '.$exception->getMessage());
        }

        $output = trim($process->getOutput());

        if ($output === '' || ! str_starts_with($output, '{')) {
            $this->markTestSkipped('composer audit produced no JSON (offline, or the binary is unavailable): '.substr($process->getErrorOutput(), 0, 200));
        }

        /** @var array<string, mixed> $report */
        $report = json_decode($output, true) ?: [];
        $severe = [];

        foreach ((array) ($report['advisories'] ?? []) as $package => $advisories) {
            foreach ((array) $advisories as $advisory) {
                $severity = strtolower((string) ($advisory['severity'] ?? ''));

                if (in_array($severity, ['high', 'critical'], true)) {
                    $severe[] = $package.': '.(string) ($advisory['title'] ?? $severity);
                }
            }
        }

        $this->assertSame([], $severe, 'SEC-38: '.implode('; ', $severe));

        $this->markTestSkipped('SEC-38 asserted composer only: `npm audit --omit=dev` and the "record the output into integrity_check_runs" half need the ops wiring of §6.6, which this slice may not touch.');
    }

    /**
     * SEC-39. The files that are on the disk are not on the web.
     *
     * Two layers again, and only one of them is this application's: the router answers 404 for
     * every one of these because none is a route, and Apache's `DocumentRoot` is what keeps the
     * project root out of reach in the first place (§6.9.1). Asserting the router half is still
     * worth doing - a catch-all route added later is exactly how `/docs/requirements.md` becomes
     * public without anybody touching the web-server configuration.
     */
    #[Test]
    public function test_env_is_not_reachable_and_not_committed(): void
    {
        foreach (self::MUST_NOT_SERVE as $path) {
            $response = $this->get($path);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                sprintf('SEC-39: %s answered %d.', $path, $response->getStatusCode()),
            );
        }

        $gitignore = base_path('.gitignore');

        $this->assertFileExists($gitignore);

        $ignored = (string) File::get($gitignore);

        $this->assertMatchesRegularExpression('/^\/?\.env$/m', $ignored, 'SEC-39: .env is not in .gitignore.');
        $this->assertStringContainsString('storage/app/backups', $ignored, 'SEC-39: storage/app/backups is not in .gitignore.');

        // `public/build` is either committed or built on deploy, and the answer has to be written
        // down either way - a half-answer is a deploy that serves a stale bundle.
        $this->assertTrue(
            str_contains($ignored, 'public/build') || File::isDirectory(public_path('build')),
            'SEC-39: public/build is neither ignored nor present - state the deploy decision either way.',
        );

        $this->assertSame([], (new SecurityAuditor)->auditEnvFile(), 'SEC-39: the .env file itself is a finding.');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Every HTML screen the manifest can resolve a real instance of, as `route name => URI`.
     *
     * @return array<string, string>
     */
    private function screens(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require base_path('tests/Support/screen-manifest.php');
        $screens = [];

        foreach ($rows as $row) {
            if (($row['response'] ?? 'html') !== 'html' || ($row['panel'] ?? '') !== 'admin') {
                continue;
            }

            $name = (string) $row['route'];

            if (Route::getRoutes()->getByName($name) === null) {
                continue;
            }

            try {
                $screens[$name] = route($name, ($row['params'])(), false);
            } catch (Throwable) {
                // No fixture for this screen in the production seed.
                continue;
            }
        }

        return $screens;
    }
}
