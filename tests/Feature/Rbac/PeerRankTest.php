<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Three Phase 1 carryover items that share one property: no `actingAs()` test could observe them
 * (DEVELOPMENT_LOG §8 **T19**, **T21**, **T16**).
 *
 * **T19 — the peer rule.** `ChecksRoleHierarchy::outranks()` is strict (`>`, not `>=`), so two
 * accounts holding the same role cannot manage each other. **That stays**: a peer who can suspend,
 * password-reset or delete their equal is a lateral takeover with nobody senior involved, and the
 * two-Admin business that wants it can give one of them a stronger role. What was wrong was the
 * *screen*: the users index lists every account, so a peer's row rendered a row-actions button that
 * opened an empty menu. It now renders a lock and says why — and says nothing about rank when rank
 * is not the reason (an actor holding only `users.view_any` has no row abilities at all).
 *
 * **T21 — a real sign-in.** `Tests\TestCase::actingAs()` forgets the session's `password_hash_web`
 * stamp, because PHPUnit shares one session Store per test and the first actor's stamp would
 * otherwise sign the second actor out. Necessary, but it means no `actingAs()` test can watch
 * `AuthenticateSession` reject a session that predates a password change — the very mechanism the
 * Phase 1 remediation added. The test here signs in for real, twice, and proves the first browser is
 * out.
 *
 * **T16 — `login_histories.user_agent` is clamped** like every other column on the row.
 */
final class PeerRankTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Satisfies App\Services\Auth\PasswordPolicy. */
    private const NEW_PASSWORD = 'Kz9!wTqm4%Ldp';

    /** Everything a row action needs, so a refusal can only be about rank. */
    private const ROW_ABILITIES = [
        'users.view_any',
        'users.view',
        'users.edit',
        'users.change_status',
        'users.delete',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->withoutCompromisedPasswordCheck();
    }

    /*
    |--------------------------------------------------------------------------
    | T19 — peers do not manage each other (decision: keep the rule strict)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function two_accounts_holding_the_same_role_cannot_manage_each_other(): void
    {
        [$alpha, $bravo] = $this->peers();

        foreach ([[$alpha, $bravo], [$bravo, $alpha]] as [$actor, $peer]) {
            foreach (['view', 'update', 'changeStatus', 'resetPassword', 'delete'] as $ability) {
                $this->assertFalse(
                    $actor->can($ability, $peer),
                    sprintf('%s must not be able to %s their peer %s.', $actor->name, $ability, $peer->name),
                );
            }
        }

        // Both hold every `users.*` ability, so the permission is not what is refusing.
        $this->assertTrue($alpha->can('users.delete'));
        $this->assertTrue($alpha->can('view', $alpha), 'Your own row is always yours to read.');
    }

    #[Test]
    public function a_peer_is_refused_by_the_server_and_not_only_by_the_screen(): void
    {
        [$alpha, $bravo] = $this->peers();

        $this->actingAs($alpha)->get('/admin/users/'.$bravo->getKey())->assertForbidden();
        $this->actingAs($alpha)->get('/admin/users/'.$bravo->getKey().'/edit')->assertForbidden();

        $this->actingAs($alpha)
            ->put('/admin/users/'.$bravo->getKey(), [
                'name' => 'Renamed By A Peer',
                'email' => (string) $bravo->email,
            ])
            ->assertForbidden();

        $this->actingAs($alpha)
            ->patch('/admin/users/'.$bravo->getKey().'/status', [
                'status' => 'suspended',
                'reason' => 'Lateral takeover attempt',
            ])
            ->assertForbidden();

        $this->actingAs($alpha)->delete('/admin/users/'.$bravo->getKey())->assertForbidden();

        $this->assertSame('Peer Bravo', $bravo->fresh()->name);
        $this->assertNull($bravo->fresh()->deleted_at);
    }

    /**
     * The honest UI (T19): a row nobody can act on says so, instead of opening an empty menu.
     *
     * The list is filtered to the one row under test, because the index also carries the seeded
     * accounts — several of which legitimately outrank the actor and therefore carry the same lock.
     */
    #[Test]
    public function a_peer_row_shows_a_lock_instead_of_an_empty_actions_menu(): void
    {
        [$alpha, $bravo] = $this->peers();

        $response = $this->actingAs($alpha)->get('/admin/users?search=Peer+Bravo');

        $response->assertOk();
        $response->assertSee('Peer Bravo');
        $response->assertSee('This account outranks yours');
        $response->assertDontSee('Actions for Peer Bravo');
        $response->assertDontSee('Edit Peer Bravo');
    }

    #[Test]
    public function an_account_the_actor_outranks_still_shows_its_actions(): void
    {
        $actor = $this->createUserWithPermissions(self::ROW_ABILITIES, 'admin', 20);

        $weaker = $this->createUserWithPermissions([], 'admin', 50, [
            'name' => 'Weaker Account',
            'email' => 'weaker.account@example.test',
        ]);

        $response = $this->actingAs($actor)->get('/admin/users?search=Weaker+Account');

        $response->assertOk();
        $response->assertSee('Actions for Weaker Account');
        $response->assertDontSee('This account outranks yours');

        $this->assertTrue($actor->can('update', $weaker));
    }

    /**
     * The lock must not lie. An actor holding only `users.view_any` can act on nobody, and that has
     * nothing to do with ranking — so the row stays blank rather than claiming to be outranked.
     */
    #[Test]
    public function a_row_with_no_per_row_permission_does_not_claim_to_outrank_the_actor(): void
    {
        $actor = $this->createUserWithPermissions(['users.view_any'], 'admin', 20);

        $this->createUserWithPermissions([], 'admin', 50, [
            'name' => 'Weaker Account',
            'email' => 'weaker.account@example.test',
        ]);

        $response = $this->actingAs($actor)->get('/admin/users?search=Weaker+Account');

        $response->assertOk();
        $response->assertSee('Weaker Account');
        $response->assertDontSee('This account outranks yours');
        $response->assertDontSee('Actions for Weaker Account');
    }

    /**
     * Your own row is not "outranked" either — the self-targeting actions are refused by design.
     */
    #[Test]
    public function your_own_row_never_claims_to_outrank_you(): void
    {
        $actor = $this->createUserWithPermissions(['users.view_any', 'users.change_status'], 'admin', 20, [
            'name' => 'Sole Status Manager',
            'email' => 'sole.status@example.test',
        ]);

        $response = $this->actingAs($actor)->get('/admin/users?search=Sole+Status+Manager');

        $response->assertOk();
        $response->assertSee('Sole Status Manager');
        $response->assertDontSee('This account outranks yours');
    }

    /*
    |--------------------------------------------------------------------------
    | T21 — two real sign-ins, and the first one is signed out
    |--------------------------------------------------------------------------
    */

    /**
     * Signs in twice through `POST /login` — no `actingAs()` anywhere — changes the password from the
     * second browser, and proves the first browser cannot come back.
     *
     * Two independent mechanisms are supposed to stop it, and both are asserted:
     *
     *   1. `PasswordChangeService::purgeStoredSessions()` deletes the account's other `sessions`
     *      rows outright, and
     *   2. `AuthenticateSession` compares the session's stored `password_hash_web` with the user's
     *      current hash and signs out any session that predates the change.
     *
     * (1) alone would make (2) untestable here, so the first browser's row is put back byte for byte
     * after the change — standing in for a session store the purge cannot reach (a `file` or `redis`
     * driver, or a cookie replayed against a restored backup). The stale stamp inside that payload is
     * asserted too, so the test cannot pass by the session simply being empty.
     */
    #[Test]
    public function a_password_change_signs_out_the_other_real_session(): void
    {
        $user = User::factory()->create([
            'name' => 'Two Browsers',
            'email' => 'two.browsers@example.test',
        ]);

        $staleHash = (string) $user->password;

        // ── Browser one ──────────────────────────────────────────────────────────────────
        $first = $this->signIn($user);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('sessions', ['id' => $first, 'user_id' => $user->getKey()]);

        $firstPayload = (string) DB::table('sessions')->where('id', $first)->value('payload');

        // ── Browser two ──────────────────────────────────────────────────────────────────
        $this->closeBrowser();
        $second = $this->signIn($user);

        $this->assertNotSame($first, $second, 'Each sign-in must land on its own session id.');
        $this->assertSame(
            2,
            DB::table('sessions')->where('user_id', $user->getKey())->count(),
            'Two real sign-ins, two stored sessions — the fixture the test depends on.'
        );

        // ── Browser two changes the password ─────────────────────────────────────────────
        $this->withCookie($this->sessionCookie(), $second)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => $first]);
        $this->assertDatabaseHas('sessions', ['id' => $second]);

        // ── Browser one comes back with its own cookie ───────────────────────────────────
        $this->closeBrowser();

        $this->withCookie($this->sessionCookie(), $first)
            ->get('/account/profile')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();

        // ── And again, with the stale session restored ───────────────────────────────────
        $stale = $this->payloadOf($firstPayload);

        // What lands in the session is an HMAC of the password hash, not the hash itself
        // (SessionGuard::hashPasswordForCookie), so the stale stamp is compared in that form. The
        // point of the assertion is unchanged: the restored payload carries the stamp of the password
        // that was in force *before* the change, which is exactly what AuthenticateSession compares.
        $this->assertSame(
            $this->app['auth']->guard('web')->hashPasswordForCookie($staleHash),
            $stale['password_hash_web'] ?? null,
            'AuthenticateSession stamps the password hash into the session; without it there is nothing to compare.'
        );

        // The bounced request above wrote an emptied session back under the same id, so the row is
        // replaced rather than inserted.
        DB::table('sessions')->where('id', $first)->delete();

        DB::table('sessions')->insert([
            'id' => $first,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PeerRankTest/first-browser',
            'payload' => $firstPayload,
            'last_activity' => time(),
        ]);

        $this->closeBrowser();

        $this->withCookie($this->sessionCookie(), $first)
            ->get('/account/profile')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();

        // ── The browser that made the change is still signed in ──────────────────────────
        $this->closeBrowser();

        $this->withCookie($this->sessionCookie(), $second)
            ->get('/account/profile')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    /*
    |--------------------------------------------------------------------------
    | T16 — the login history row is clamped like its siblings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_oversized_user_agent_is_clamped_before_it_is_stored(): void
    {
        $user = User::factory()->create(['email' => 'long.agent@example.test']);

        $this->withHeader('User-Agent', str_repeat('A', 5000))
            ->post('/login', ['email' => (string) $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);

        $row = LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'A successful sign-in must still be recorded.');
        $this->assertSame(1024, mb_strlen((string) $row->user_agent));
    }

    #[Test]
    public function a_normal_user_agent_is_stored_untouched(): void
    {
        $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

        $user = User::factory()->create(['email' => 'normal.agent@example.test']);

        $this->withHeader('User-Agent', $agent)
            ->post('/login', ['email' => (string) $user->email, 'password' => 'password']);

        $row = LoginHistory::query()->where('user_id', $user->getKey())->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame($agent, (string) $row->user_agent, 'Clamping must not truncate a real agent string.');
        $this->assertSame('desktop', $row->device);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Two accounts holding **the same** role — peers by definition, not merely by level.
     *
     * @return array{0: User, 1: User}
     */
    private function peers(): array
    {
        $role = $this->createRoleWithPermissions(self::ROW_ABILITIES, 'admin', 20, 'Peer Administrator');

        $users = [];

        foreach ([['Peer Alpha', 'peer.alpha@example.test'], ['Peer Bravo', 'peer.bravo@example.test']] as [$name, $email]) {
            $user = User::factory()->create(['name' => $name, 'email' => $email]);
            $user->assignRole($role);

            $users[] = $user;
        }

        $this->forgetPermissionCache();

        return [
            $users[0]->fresh()->load('roles'),
            $users[1]->fresh()->load('roles'),
        ];
    }

    /**
     * A real sign-in, returning the session id the response leaves the browser holding.
     *
     * The id is read off the session store rather than guessed: `AuthenticatedSessionController`
     * regenerates the session after the credentials are accepted, so the id that ends up in the
     * cookie (and in the `sessions` row) is not the one the request arrived with.
     *
     * The follow-up GET is not decoration. `AuthenticateSession` stamps `password_hash_web` on the
     * first request that reaches it *while already authenticated* — during `POST /login` the
     * middleware runs before the credentials are accepted, so `$request->user()` is still null and
     * nothing is stamped. A real browser always makes that next request (it follows the redirect to
     * the panel); without it here the stored payload would carry no hash to compare and the stale
     * session would be let through for the wrong reason.
     */
    private function signIn(User $user): string
    {
        $this->post('/login', [
            'email' => (string) $user->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $id = (string) $this->app['session']->getId();

        // The cookie has to be stated: without one `StartSession` mints a fresh id for the follow-up
        // request (the in-memory attributes carry over, so it still looks signed in), and the stamp
        // would land on a second row instead of the one this browser is holding.
        $this->withCookie($this->sessionCookie(), $id)
            ->get('/account/profile')
            ->assertOk();

        return $id;
    }

    /**
     * Drop the two pieces of state PHPUnit shares between requests but a real browser does not: the
     * in-memory session attributes and the already-resolved guard. After this, the only thing that
     * identifies a session is the cookie the next request carries — which is the whole point of the
     * test.
     *
     * Nothing is written: `flushSession()` clears the store in memory without saving, so the stored
     * row of the session being "closed" is left exactly as the last request left it.
     */
    private function closeBrowser(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // `withCookie()` writes into `$defaultCookies`, which PHPUnit then sends on *every* later
        // request. Left in place it would hand the next "browser" the previous one's session cookie —
        // and because that session is still authenticated, `POST /login` would be bounced by the
        // `guest` middleware and both browsers would share one session id.
        $this->defaultCookies = [];
    }

    /**
     * The session cookie's real name — `config('session.cookie')` is derived from APP_NAME, so it is
     * not `laravel_session` in this application.
     */
    private function sessionCookie(): string
    {
        return (string) config('session.cookie');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(string $payload): array
    {
        $decoded = base64_decode($payload, true);
        $data = $decoded === false ? null : @unserialize($decoded);

        return is_array($data) ? $data : [];
    }
}
