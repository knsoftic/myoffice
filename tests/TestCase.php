<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Modules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run DatabaseSeeder on the test database.
     *
     * This application is RBAC-driven: roles, permissions, modules and settings live in the
     * database, so an unseeded schema is not a usable fixture — `Gate` has nothing to resolve
     * against and every panel route denies. RefreshDatabase performs exactly one `migrate:fresh`
     * per test process and only passes `--seed` when the class that triggers it asks for it, so
     * the flag has to live here rather than on individual test classes: otherwise whichever class
     * happens to run first decides whether the whole suite sees seeded data.
     *
     * Cost is paid once for the whole suite; per-test changes are still rolled back by the
     * transaction RefreshDatabase opens.
     *
     * @var bool
     */
    protected $seed = true;

    /**
     * `App\Support\Modules` memoises the slug => is_enabled map and the permission => module map
     * in **static** properties. Statics outlive the application instance, so a test that disables
     * a module would otherwise leak that state into every later test in the same PHPUnit process
     * (and into the seeded fixture, which is not rolled back because it predates the
     * transaction). Flushing on both ends of every test keeps the module gate honest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->flushModuleGateCache();
    }

    protected function tearDown(): void
    {
        $this->flushModuleGateCache();

        parent::tearDown();
    }

    /**
     * Switch the acting user the way a real sign-in does.
     *
     * `Illuminate\Session\Middleware\AuthenticateSession` is registered in the web group (phase-01
     * §7: `Auth::logoutOtherDevices()` is inert without it), and it signs a request out when the
     * session's stored `password_hash_<guard>` does not match the authenticated user's hash.
     *
     * Inside one test the session Store is a singleton and `Store::loadSession()` merges the
     * handler's payload into the attributes it already holds, so the hash stamped for the first
     * actor survives into the next request — and a second `actingAs()` with a *different* user is
     * then bounced to /login. A genuine login cannot hit this: `AuthenticatedSessionController`
     * regenerates the session, which is why production is unaffected and only the harness needs to
     * say so. Forgetting the stamp (rather than flushing the whole session) keeps flashed input,
     * validation errors and the `from()` referer intact for the assertions that read them.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        try {
            if ($this->app !== null && $this->app->bound('session')) {
                $session = $this->app->make('session');

                foreach (['web', $guard, config('auth.defaults.guard')] as $name) {
                    if (is_string($name) && $name !== '') {
                        $session->forget('password_hash_'.$name);
                    }
                }
            }
        } catch (Throwable) {
            // No application or session bound yet: there is no stale stamp to clear.
        }

        return parent::actingAs($user, $guard);
    }

    private function flushModuleGateCache(): void
    {
        try {
            Modules::flushCache();
        } catch (Throwable) {
            // No application / cache store bound yet: there is nothing memoised to flush.
        }
    }
}
