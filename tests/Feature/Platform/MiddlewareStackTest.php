<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The middleware every request inherits, and the two promises that depend on it
 * (phase-01 §6, §7, §10).
 *
 * 1. `AuthenticateSession` in the `web` group. Phase-01 §7 says a password change invalidates the
 *    user's other sessions through `Auth::logoutOtherDevices()`. That call only rehashes the
 *    password: the middleware that compares a session's stamped hash against the user's current one
 *    is what actually revokes the other sessions, and it was never registered. The side channel
 *    that deleted rows from `sessions` covered it up on the database driver — move
 *    `SESSION_DRIVER` to file, redis or cookie and a stolen cookie would have survived a password
 *    reset outright.
 *
 * 2. `active` on every authenticated route, not just on /account. EnsureUserIsActive is what makes
 *    a suspension take effect on a session that is already open, and it only runs where it is
 *    listed — so a suspended user could still re-set their own password on `PUT /password`, ask for
 *    another verification mail and confirm their password, on an account that may no longer sign in.
 */
final class MiddlewareStackTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Satisfies App\Services\Auth\PasswordPolicy. */
    private const NEW_PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->withoutCompromisedPasswordCheck();
    }

    /*
    |--------------------------------------------------------------------------
    | The web group
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_web_group_authenticates_the_session(): void
    {
        $kernel = $this->app->make(KernelContract::class);

        $this->assertInstanceOf(Kernel::class, $kernel);

        $web = $kernel->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            AuthenticateSession::class,
            $web,
            'Without it Auth::logoutOtherDevices() revokes nothing (phase-01 §7).'
        );

        // It has to sit behind StartSession — it reads and writes the session.
        $this->assertGreaterThan(
            array_search(StartSession::class, $web, true),
            array_search(AuthenticateSession::class, $web, true),
        );

        // And it reaches every authenticated screen, because every panel route file is loaded
        // inside the `web` group. Asked of the router, so the group is expanded to real classes.
        foreach (['admin.dashboard', 'account.password', 'student.dashboard'] as $name) {
            $this->assertContains(
                AuthenticateSession::class,
                $this->app['router']->gatherRouteMiddleware(Route::getRoutes()->getByName($name)),
                $name.' must inherit the session check.'
            );
        }
    }

    /**
     * The mechanism itself: a session carrying a password hash that no longer matches the user's is
     * signed out on its next request. That is what revokes a session the application cannot reach
     * and delete — another device on a file/redis session driver, or a `sessions` row written after
     * the purge.
     */
    #[Test]
    public function a_session_whose_password_hash_is_stale_is_signed_out(): void
    {
        $user = User::factory()->create();

        $fresh = $this->storeSessionFor($user, (string) $user->password);
        $stale = $this->storeSessionFor($user, Hash::make('the-password-this-account-had-last-week'));

        // Control: the same crafted session, with the current hash, is accepted.
        $this->withCookie($this->sessionCookie(), $fresh)
            ->get('/account/profile')
            ->assertOk();

        $this->startFreshRequest();

        $this->withCookie($this->sessionCookie(), $stale)
            ->get('/account/profile')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }

    /**
     * The other half of AuthenticateSession: the session that performs the change must survive it,
     * or changing your password would sign you out of the device you are holding.
     */
    #[Test]
    public function the_session_that_changes_the_password_is_re_stamped_rather_than_revoked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/account/password')
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('password_hash_'.Auth::getDefaultDriver());

        $this->assertAuthenticatedAs($user);

        $stamped = (string) session('password_hash_'.Auth::getDefaultDriver());
        $current = (string) $user->fresh()->password;

        $guard = Auth::guard();
        $accepted = [$current];

        // Laravel stamps an HMAC of the hash where the guard can build one, and the raw hash
        // otherwise; the middleware accepts either, so this does too.
        if (method_exists($guard, 'hashPasswordForCookie')) {
            $accepted[] = $guard->hashPasswordForCookie($current);
        }

        $this->assertContains(
            $stamped,
            $accepted,
            'The surviving session must carry the NEW hash, or its next request would log it out.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | `active` on the authenticated routes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_authenticated_route_carries_the_active_middleware(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            $checked++;

            $this->assertContains(
                'active',
                $middleware,
                sprintf(
                    'Route %s requires a session but never checks the account is still active.',
                    (string) ($route->getName() ?: $route->uri()),
                )
            );
        }

        $this->assertGreaterThanOrEqual(
            40,
            $checked,
            'The walker found almost no authenticated routes — it is passing vacuously.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function suspendedStateProvider(): array
    {
        return [
            'suspended' => ['suspended'],
            'inactive' => ['inactive'],
        ];
    }

    #[Test]
    #[DataProvider('suspendedStateProvider')]
    public function a_suspended_account_cannot_change_its_password_on_the_breeze_endpoint(string $state): void
    {
        $user = User::factory()->{$state}()->create();

        $this->actingAs($user)
            ->put('/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
        $this->assertTrue(
            Hash::check('password', (string) $user->fresh()->password),
            'An account that may not sign in may not re-set its credentials either.'
        );
    }

    #[Test]
    public function a_suspended_account_cannot_change_its_password_on_the_account_screen(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('login', absolute: false));

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authenticatedEndpointProvider(): array
    {
        return [
            'verification notice' => ['get', '/verify-email'],
            'verification resend' => ['post', '/email/verification-notification'],
            'password confirmation screen' => ['get', '/confirm-password'],
        ];
    }

    #[Test]
    #[DataProvider('authenticatedEndpointProvider')]
    public function a_suspended_account_is_turned_away_from_the_other_authenticated_endpoints(string $method, string $url): void
    {
        $user = User::factory()->suspended()->unverified()->create();

        $this->actingAs($user)
            ->{$method}($url)
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }

    /**
     * The forced-password-change flow must not deadlock: `password.update` is on
     * EnsureUserIsActive's allow list, so an **active** account with the flag set can still use the
     * Breeze endpoint — the new `active` middleware on the group does not close it.
     */
    #[Test]
    public function an_active_account_with_a_forced_change_can_still_use_the_breeze_endpoint(): void
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $this->actingAs($user)
            ->from('/account/password')
            ->put('/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/account/password');

        $user->refresh();

        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->password));
        $this->assertAuthenticatedAs($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Write a `sessions` row that is already signed in as the user and already carries a stamped
     * password hash — the shape AuthenticateSession reads. InteractsWithRbac::createSessionRow()
     * writes the "another device" row; this one needs a payload with real auth state in it.
     *
     * @return string the session id
     */
    private function storeSessionFor(User $user, string $passwordHash): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '198.51.100.9',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'payload' => base64_encode(serialize([
                '_token' => Str::random(40),
                Auth::guard()->getName() => $user->getKey(),
                'password_hash_'.Auth::getDefaultDriver() => $passwordHash,
            ])),
            'last_activity' => time(),
        ]);

        return $id;
    }

    private function sessionCookie(): string
    {
        return (string) config('session.cookie');
    }

    /**
     * Forget whatever the previous request left behind — the session store keeps its attributes
     * between requests in one test, and the guard caches the user it resolved — so the next request
     * is decided by the crafted session row alone.
     */
    private function startFreshRequest(): void
    {
        Auth::forgetGuards();

        $this->app['session']->flush();
        $this->app['session']->setId(Str::random(40));
    }
}
