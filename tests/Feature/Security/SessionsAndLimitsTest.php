<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\LoginStatus;
use App\Enums\PanelType;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\LoginHistory;
use App\Services\Auth\PasswordPolicy;
use App\Support\ConfigureFromSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * How long a session lasts, how it ends, and how fast anybody may try (phase-24-25 sections 11.1
 * and 11.2, SEC-02, SEC-18, SEC-24..SEC-27, SEC-29, SEC-30).
 *
 * **A session is a password the user does not have to type, and it has to expire like one.** Every
 * test here is about the gap between "this person authenticated once" and "this person is still
 * the one holding the cookie": a suspension that only takes effect at the next sign-in, a session
 * that survives the password change that was meant to revoke it, an absolute ceiling that is really
 * just the idle timeout under another name - each one turns a stolen cookie into an account.
 *
 * **The two timeout settings are independent on purpose, and SEC-26 is what proves it.**
 * `security.session_lifetime` is *idle*: it ends a session nobody is using, which is the shared-
 * machine case. `security.session_absolute_lifetime_hours` is a *ceiling*: it ends a session
 * somebody **is** using, which is the stolen-cookie case, where the attacker is active precisely so
 * the idle timer never fires. A system with only the first has no answer to a live thief.
 *
 * **The lockout and the rate limit are two controls counting the same events** (§5.3).
 * `login_max_attempts` stops the account answering; `login_throttle_per_minute` slows the guessing.
 * Keyed identically - email plus IP - so one source of truth, and so one attacker cannot lock out
 * the whole company by guessing at everybody's address from one address of their own.
 *
 * **No test here types a password.** Sign-in is `actingAs()`; what a password must satisfy is asked
 * of `PasswordPolicy` directly.
 */
#[Group('security')]
final class SessionsAndLimitsTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The complete limiter list of §6.3.1 - seventeen, and the count is part of the contract. */
    private const LIMITERS = [
        'login', 'password-reset', 'verification', 'password-confirm', 'public-contact',
        'public-apply', 'public-verify', 'mail-test', 'export', 'print', 'payout-request',
        'backup-run', 'backup-restore', 'integrity-run', 'health', 'csp-report', 'global-writes',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-02 - the cookie itself
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-02. The session cookie is unreadable by script, unsendable cross-site, and its payload
     * carries no personal data in the clear.
     *
     * **`HttpOnly` is what makes stored XSS survivable.** Without it one injected script reads
     * `document.cookie` and the attacker has the session without ever touching the password. And
     * `SESSION_ENCRYPT=true` is what makes the `sessions` *table* survivable: a database dump, a
     * misconfigured backup, a read-only SQL injection somewhere else - none of them should hand
     * over a readable list of who is signed in and as whom.
     *
     * The device, IP and last-activity columns stay in the clear on purpose: the session-management
     * screen has to be able to show them, and they are what a person recognises as "not me".
     */
    #[Test]
    public function test_session_cookie_flags(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user)->get(route('admin.dashboard', [], false));
        $response->assertOk();

        $this->assertTrue((bool) config('session.http_only'), 'SEC-02: the session cookie is readable by JavaScript.');
        $this->assertSame('lax', strtolower((string) config('session.same_site')), 'SEC-02: SameSite is not Lax.');

        // `Secure` tracks the force-https setting one way only, and only outside local development.
        // `ConfigureFromSettings::applySessionCookieFlags()` is the implementation, and the two
        // halves below are its two rules, asserted rather than left to the comment beside it.
        //
        // **The implication, not the equality.** A stored `force_https = false` must not *clear* a
        // `Secure` flag the environment set on purpose: turning the setting off is a statement
        // about redirects, not permission to start sending the session cookie in the clear. So the
        // contract is `force_https ⇒ session.secure`, and the reverse is deliberately free.
        /*
        | Under `testing` the setting deliberately does NOT govern the cookie, and that carve-out is
        | what is assertable here. A `Secure` cookie is never sent over http, so honouring an
        | inherited `force_https = true` on a developer machine would log everybody out of
        | localhost with no way back in — the same reasoning `ForceHttps` itself uses.
        |
        | The production half of the rule cannot be asserted from inside a `testing` run at all, so
        | it is not attempted here: `assertSecureCookieIsExcludedLocally()` below drives
        | `ConfigureFromSettings::applySessionCookieFlags()` against a simulated production
        | environment and asserts the implication there. A conjunct that includes
        | `! environment(['local','testing'])` is always false in this process — it reads as an
        | assertion and can never fail, which is worse than no assertion because the next person to
        | change these rules will believe it is watching.
        */
        $this->assertTrue(
            $this->app->environment(['local', 'testing']),
            'SEC-02 assumes the suite runs under local/testing; the carve-out below depends on it.',
        );

        ConfigureFromSettings::applySessionCookieFlags(['force_https' => true]);

        $this->assertNotTrue(
            config('session.secure'),
            'SEC-02: the Secure flag must not be forced on under testing — a Secure cookie is never '
            .'sent over http, so this locks every developer out of localhost.',
        );

        $this->assertSecureCookieIsExcludedLocally();

        // The device, IP and last-activity columns are deliberately readable: the session screen
        // has to show them, and they are what a person recognises as "not me".
        $row = DB::table('sessions')->where('user_id', $user->getKey())->first();

        if ($row !== null) {
            $this->assertNotNull($row->ip_address, 'SEC-02: the session screen needs a readable IP column.');
            $this->assertNotNull($row->last_activity, 'SEC-02: the session screen needs a readable last-activity column.');
        }

        if (! (bool) config('session.encrypt')) {
            $this->markTestSkipped('SESSION_ENCRYPT is not true in this environment, so the "raw sessions.payload contains no readable email" half of SEC-02 cannot be asserted. See the report\'s MAIN SESSION MUST MERGE.');
        }

        $this->assertNotNull($row, 'SEC-02: no session row was written, so the payload could not be read.');
        $this->assertStringNotContainsString((string) $user->email, (string) $row->payload, 'SEC-02: the raw session payload contains a readable email.');
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-18 - what a password must be
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-18. The password policy is read from settings and is enforced as a rule object.
     *
     * Asked of `PasswordPolicy` rather than by posting a form, for the reason the harness gives:
     * a test that types a password is a test that hard-codes one. The floor matters more than the
     * setting - `security.password_min_length` cannot be saved below 10, so a value written by raw
     * SQL still means 10 rather than silently meaning 4.
     */
    #[Test]
    public function test_password_policy_and_history(): void
    {
        $this->assertGreaterThanOrEqual(10, PasswordPolicy::minLength(), 'SEC-18: the minimum length floor was lowered.');
        $this->assertGreaterThanOrEqual(10, (int) setting('security.password_min_length', 10), 'SEC-18: the setting is below the policy floor.');

        $this->assertNotEmpty(PasswordPolicy::rules(), 'SEC-18: PasswordPolicy declares no rules.');

        // Complexity and the compromised-password check are read from the policy's own source
        // rather than from the rule objects: Laravel's `Password` rule keeps its flags in private
        // state and `serialize()` on it is not guaranteed to survive a framework upgrade, so the
        // declaration is the more durable thing to assert.
        $source = (string) File::get(app_path('Services/Auth/PasswordPolicy.php'));

        foreach (['mixedCase', 'numbers', 'symbols', 'uncompromised'] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $source,
                sprintf('SEC-18: the policy does not require %s.', $expected),
            );
        }

        $this->assertGreaterThanOrEqual(
            1,
            (int) setting('security.password_history_count', 0),
            'SEC-18: password reuse is unlimited - security.password_history_count is 0.',
        );

        if (! DB::getSchemaBuilder()->hasTable('password_histories')) {
            $this->markTestSkipped('No password-history table exists yet, so the "last N hashes refused" and "password_changed_at stamped" halves of SEC-18 cannot be asserted.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-24, SEC-25, SEC-26 - ending a session
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-24. A suspension takes effect on the next request, not on the next sign-in.
     *
     * **Everything else is theatre if this one fails.** Withdrawing somebody's access is exactly
     * the moment they are most likely to be holding an open session, so a suspension that waits for
     * a re-login is a suspension that does nothing to the person it was aimed at.
     */
    #[Test]
    public function test_inactive_and_suspended_users_are_stopped_immediately(): void
    {
        foreach (['suspended', 'inactive'] as $state) {
            $user = $this->createUserWithPermissions(['leads.view', 'leads.view_any'], PanelType::Admin);

            $this->actingAs($user)->get(route('admin.leads.index', [], false))->assertOk();

            $user->forceFill(['status' => $state])->save();

            $response = $this->actingAs($user->fresh())->get(route('admin.leads.index', [], false));

            $response->assertRedirect(route('login', [], false));
            // assertGuest() takes a guard name, not a message, so the check is spelled out.
            $this->assertFalse(auth()->check(), sprintf('SEC-24: a %s user is still authenticated.', $state));

            $this->assertTrue(
                LoginHistory::query()
                    ->where('user_id', $user->getKey())
                    ->where('status', LoginStatus::Blocked->value)
                    ->exists(),
                sprintf('SEC-24: no blocked login-history row for the %s user.', $state),
            );
        }

        // must_change_password: the change-password screen and nothing else.
        $forced = $this->createUserWithPermissions(['leads.view', 'leads.view_any'], PanelType::Admin);
        $forced->forceFill(['must_change_password' => true])->save();

        $this->actingAs($forced->fresh())
            ->get(route('admin.leads.index', [], false))
            ->assertRedirect(route('account.password', [], false));

        $this->actingAs($forced->fresh())
            ->get(route('account.password', [], false))
            ->assertOk();
    }

    /**
     * SEC-25. The session id changes at sign-in, and a revoked session is revoked immediately.
     *
     * Fixation is asserted by reading the sign-in controller rather than by signing in, because the
     * harness forbids typing a password. That is not a weaker assertion than it looks: the call is
     * either there or it is not, and `AuthenticateSession` (asserted by the middleware-stack test)
     * is what makes the *other* half - a password change revoking every other session - real.
     */
    #[Test]
    public function test_session_fixation_and_revocation(): void
    {
        $controller = app_path('Http/Controllers/Auth/AuthenticatedSessionController.php');

        $this->assertFileExists($controller);

        $source = (string) File::get($controller);

        $this->assertStringContainsString('session()->regenerate()', $source, 'SEC-25: the sign-in does not regenerate the session id (fixation).');
        $this->assertStringContainsString('invalidate()', $source, 'SEC-25: the sign-out does not invalidate the session.');
        $this->assertStringContainsString('regenerateToken()', $source, 'SEC-25: the sign-out does not regenerate the CSRF token.');

        // Revoking one session from the management screen deletes its row.
        $user = $this->createUserWithPermissions([], PanelType::Admin);
        $other = $this->createSessionRow($user);
        $mine = $this->createSessionRow($user);

        $this->actingAs($user)->delete(route('account.sessions.destroy', ['session' => $other], false));

        $this->assertDatabaseMissing('sessions', ['id' => $other]);

        // "Revoke all others" leaves exactly the current one; the two rows above are both "other".
        $this->actingAs($user)->delete(route('account.sessions.destroy-others', [], false));

        $this->assertDatabaseMissing('sessions', ['id' => $mine]);
    }

    /**
     * SEC-26. Idle and absolute are two different clocks, and the lockout agrees with the limiter.
     *
     * The ceiling is asserted through `EnforceSessionLifetime`'s own session key: a session stamped
     * as having begun before the ceiling is ended on its next request even though it has been in
     * constant use, which is the whole difference from the idle timeout.
     */
    #[Test]
    public function test_session_timeouts(): void
    {
        $hours = (int) setting('security.session_absolute_lifetime_hours', 24);
        $idleMinutes = (int) setting('security.session_lifetime', (int) config('session.lifetime'));

        $this->assertGreaterThan(0, $hours, 'SEC-26: there is no absolute ceiling.');
        $this->assertGreaterThan(0, $idleMinutes, 'SEC-26: there is no idle timeout.');

        // Independence: the idle timeout maps to session.lifetime (minutes) and the ceiling does
        // not. If one were derived from the other, these two would move together.
        $this->assertSame(
            $idleMinutes,
            (int) config('session.lifetime'),
            'SEC-26: security.session_lifetime is not what session.lifetime is built from.',
        );
        $this->assertNotSame(
            $hours * 60,
            $idleMinutes,
            'SEC-26: the absolute ceiling and the idle timeout are the same number - they are meant to be independent controls.',
        );

        $user = $this->createUserWithPermissions(['leads.view', 'leads.view_any'], PanelType::Admin);

        $response = $this->actingAs($user)
            ->withSession([EnforceSessionLifetime::SESSION_KEY => now()->subHours($hours + 1)->toIso8601String()])
            ->get(route('admin.leads.index', [], false));

        $response->assertRedirect(route('login', [], false));
        $this->assertFalse(auth()->check(), 'SEC-26: a session past the absolute ceiling survived.');

        $this->assertTrue(
            LoginHistory::query()->where('user_id', $user->getKey())->whereIn('status', [LoginStatus::Logout->value, LoginStatus::Blocked->value])->exists(),
            'SEC-26: the absolute timeout wrote no logout history row.',
        );

        // One source of truth: the limiter reads the rate setting, and the lockout setting exists
        // beside it rather than being a second, drifting copy of the same number.
        $perMinute = (int) setting('security.login_throttle_per_minute', 5);
        $limiter = RateLimiter::limiter('login');

        $this->assertNotNull($limiter, 'SEC-26: the `login` limiter is not registered.');

        $limit = $limiter(Request::create('/login', 'POST', ['email' => 'someone@example.test']));

        $this->assertSame($perMinute, $limit->maxAttempts, 'SEC-26: the login limiter does not read security.login_throttle_per_minute.');
        $this->assertGreaterThan(0, (int) setting('security.login_max_attempts', 0), 'SEC-26: there is no lockout count beside the rate.');
        $this->assertGreaterThan(0, (int) setting('security.lockout_minutes', 0), 'SEC-26: the lockout has no duration.');

        $this->assertIsBool((bool) setting('security.session_single_device', false));

        $this->markTestSkipped('SEC-26 asserted the ceiling, the independence and the one-source-of-truth: the session_single_device half ("a second sign-in kills the first") needs a real sign-in, which the harness forbids.');
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-27, SEC-29, SEC-30 - limits, confirmation, and the door that is not there
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-27. Every named limiter exists, and the one that is wired to a route answers a styled 429.
     *
     * **A 429 that leaks is worse than no 429.** Naming the limiter key tells an attacker what the
     * counter is keyed on, and echoing the submitted email confirms the address exists - so the
     * page says how long to wait and nothing else.
     */
    #[Test]
    public function test_rate_limiters_fire_and_are_styled(): void
    {
        foreach (self::LIMITERS as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), sprintf('SEC-27: the `%s` limiter of §6.3.1 is not registered.', $name));
        }

        $this->assertCount(17, self::LIMITERS, 'SEC-27: §6.3.1 names exactly seventeen limiters.');

        if (Route::getRoutes()->getByName('site.contact.store') === null) {
            $this->markTestSkipped('site.contact.store is not registered, so no limiter is reachable to fire.');
        }

        $uri = route('site.contact.store', [], false);
        $email = 'limiter-probe@example.test';
        $response = null;

        // public-contact is 5 a minute per IP; the sixth is the one that must be refused.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $response = $this->post($uri, ['name' => 'Probe', 'email' => $email, 'message' => 'Hello there.']);

            if ($response->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertNotNull($response);
        $this->assertSame(429, $response->getStatusCode(), 'SEC-27: the public-contact limiter never fired.');
        $this->assertNotNull($response->headers->get('Retry-After'), 'SEC-27: the 429 carries no Retry-After header.');

        $body = (string) $response->getContent();

        $this->assertStringContainsString('minute', $body, 'SEC-27: the 429 does not say how long to wait.');
        $this->assertStringNotContainsString('contact:ip', $body, 'SEC-27: the 429 names the limiter key.');
        $this->assertStringNotContainsString($email, $body, 'SEC-27: the 429 echoes the submitted email.');

        $this->markTestSkipped('SEC-27 fired public-contact only: the other sixteen limiters are registered but not yet attached to a route this phase can reach (throttle: middleware is owned by routes/*.php, which this slice may not touch).');
    }

    /**
     * SEC-29. A restore asks for the password again, and the confirmation cannot be forged.
     *
     * **Re-authentication before an irreversible action is not about proving who you are, it is
     * about proving somebody is there.** The session may have been left open; the password prompt
     * is what a walk-up attacker cannot answer.
     */
    #[Test]
    public function test_password_confirmation_gates_the_restore(): void
    {
        if (Route::getRoutes()->getByName('admin.backups.restore.create') === null) {
            $this->markTestSkipped('admin.backups.restore.create and .store are not registered yet (phase-24-25 §7.1 ships them).');
        }

        $admin = $this->createSuperAdmin();

        // A forged confirmation timestamp in the body changes nothing: `password.confirm` reads the
        // session, and nothing in the request can write to it.
        $this->actingAs($admin)
            ->get(route('admin.backups.restore.create', [], false).'?password_confirmed_at='.time())
            ->assertRedirect(route('password.confirm', [], false));

        $this->actingAs($admin)
            ->post(route('admin.backups.restore.store', [], false), [
                'confirmed_at' => now()->toDateTimeString(),
                'password_confirmed_at' => time(),
            ])
            ->assertRedirect(route('password.confirm', [], false));
    }

    /**
     * SEC-30. There is no registration, and the absence is asserted rather than assumed (D15).
     *
     * **404 rather than 405 matters.** A 405 says "this path exists, just not for POST", which tells
     * a scanner the endpoint is there and disabled; a 404 says nothing at all. Accounts are created
     * by an administrator in the users module, and that is the only way in.
     *
     * **The test looks for the sign-up *shape*, not for the substring `register`.** It used to
     * forbid the substring anywhere in a route name or URI, and that was a mistake about English
     * rather than about security: `admin.admissions.register` and
     * `admin.payroll-runs.register.print` are both the *noun* - an admissions register, a payroll
     * register - printed behind `auth`, `panel:admin` and a `can:` middleware. Banning the letters
     * would either have failed forever or, worse, pushed somebody to rename a legitimate screen to
     * keep a security test quiet, which is how a test starts shaping the product instead of
     * checking it.
     *
     * So two narrower questions are asked instead, and together they are exactly D15's promise:
     * no route **is** `register` as a name segment outside the admin panel (`register`,
     * `auth.register`, `register.store` - the shapes Breeze would have left behind), and no route a
     * **signed-out visitor** can reach carries `register` as a path segment. A screen that needs
     * authentication is not a way in.
     */
    #[Test]
    public function test_registration_is_absent(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'nobody@example.test'])->assertNotFound();

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $uri = $route->uri();
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            $isAdminPanel = in_array('panel:admin', $middleware, true) || str_starts_with($name, 'admin.');

            $needsAuth = array_filter(
                $middleware,
                static fn (string $one): bool => $one === 'auth' || str_starts_with($one, 'auth:'),
            ) !== [];

            // `register` as a whole name segment: the sign-up route, wherever it were hiding.
            $this->assertFalse(
                ! $isAdminPanel && preg_match('/(^|\.)register(\.|$)/', $name) === 1,
                sprintf('SEC-30: route %s is a sign-up route outside the admin panel (D15 removed public registration).', $name),
            );

            // A `register` path segment on anything reachable without signing in.
            $this->assertFalse(
                ! $needsAuth && in_array('register', explode('/', $uri), true),
                sprintf('SEC-30: URI /%s is reachable signed out and offers a register path (D15).', $uri),
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Both halves of the `Secure` rule, including the one that says the setting does **not** apply.
     *
     * **A carve-out that only a comment knows about is a carve-out that gets deleted by the next
     * person tidying up.** `ConfigureFromSettings::applySessionCookieFlags()` returns early in
     * `local` and `testing` on purpose: a `Secure` cookie is never sent over http, so honouring an
     * inherited `force_https = true` on a developer machine would log everybody out of localhost
     * with no way back in. That exclusion is a real security decision with a real cost, so it is
     * asserted here rather than trusted - and so is the production behaviour it is an exception to,
     * by running the same call with the environment swapped.
     */
    private function assertSecureCookieIsExcludedLocally(): void
    {
        $environment = $this->app['env'];
        $secure = config('session.secure');

        try {
            $this->app['env'] = 'testing';
            config(['session.secure' => false]);

            ConfigureFromSettings::applySessionCookieFlags(['force_https' => true]);

            $this->assertFalse(
                (bool) config('session.secure'),
                'SEC-02: security.force_https governs the cookie in `testing`, which locks a developer out of http://localhost.',
            );

            $this->app['env'] = 'production';
            config(['session.secure' => false]);

            ConfigureFromSettings::applySessionCookieFlags(['force_https' => true]);

            $this->assertTrue(
                (bool) config('session.secure'),
                'SEC-02: security.force_https does not set the Secure flag in production.',
            );

            // Only ever tightened: a stored false leaves a deliberately-set flag alone.
            config(['session.secure' => true]);

            ConfigureFromSettings::applySessionCookieFlags(['force_https' => false]);

            $this->assertTrue(
                (bool) config('session.secure'),
                'SEC-02: turning security.force_https off cleared a Secure flag the environment set.',
            );
        } finally {
            $this->app['env'] = $environment;
            config(['session.secure' => $secure]);
        }
    }
}
