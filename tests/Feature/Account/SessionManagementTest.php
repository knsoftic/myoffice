<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * "Where am I signed in?" — the replacement for Breeze's delete-account form (phase-01 §1.8, §7).
 *
 * The route takes a session id, but every read and every delete is scoped `where user_id = <me>`,
 * so a guessed id belonging to someone else resolves to nothing (CLAUDE.md rule 10 — isolation by
 * the owning relation, never by a value from the request).
 */
final class SessionManagementTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const SCREEN = '/account/sessions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function the_sessions_screen_renders_and_lists_the_users_own_devices(): void
    {
        $user = User::factory()->create();
        $mine = $this->createSessionRow($user, ip: '203.0.113.9');
        $theirs = $this->createSessionRow(User::factory()->create(), ip: '203.0.113.77');

        $response = $this->actingAs($user)->get(self::SCREEN);

        $response->assertOk();
        $response->assertSee('203.0.113.9');
        $response->assertDontSee('203.0.113.77');

        $this->assertNotSame($mine, $theirs);
    }

    #[Test]
    public function a_guest_cannot_reach_the_sessions_screen(): void
    {
        $this->get(self::SCREEN)->assertRedirect(route('login', absolute: false));
    }

    /*
    |--------------------------------------------------------------------------
    | Revoking one
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_can_revoke_one_of_their_own_sessions(): void
    {
        $user = User::factory()->create();
        $other = $this->createSessionRow($user);

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->delete(self::SCREEN.'/'.$other)
            ->assertRedirect(self::SCREEN)
            ->assertSessionHas('toast.type', 'success');

        $this->assertFalse(DB::table('sessions')->where('id', $other)->exists());
    }

    #[Test]
    public function revoking_a_session_that_has_already_ended_is_reported_not_an_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->delete(self::SCREEN.'/thisSessionNeverExisted')
            ->assertRedirect(self::SCREEN)
            ->assertSessionHas('toast.type', 'info');
    }

    /**
     * The isolation test the contract asks for: a user may not revoke someone else's session, even
     * holding its exact id.
     */
    #[Test]
    public function a_user_cannot_revoke_another_users_session(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        $theirSession = $this->createSessionRow($someoneElse);

        $this->actingAs($me)
            ->from(self::SCREEN)
            ->delete(self::SCREEN.'/'.$theirSession)
            ->assertRedirect(self::SCREEN)
            ->assertSessionHas('toast.type', 'info');

        $this->assertTrue(
            DB::table('sessions')->where('id', $theirSession)->exists(),
            'Another user\'s session must survive — the delete is scoped to the owner.'
        );
    }

    #[Test]
    public function a_guest_cannot_revoke_a_session(): void
    {
        $someoneElse = User::factory()->create();
        $theirSession = $this->createSessionRow($someoneElse);

        $this->delete(self::SCREEN.'/'.$theirSession)
            ->assertRedirect(route('login', absolute: false));

        $this->assertTrue(DB::table('sessions')->where('id', $theirSession)->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | Revoking the rest
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_can_revoke_every_other_session_at_once(): void
    {
        $user = User::factory()->create();

        $first = $this->createSessionRow($user);
        $second = $this->createSessionRow($user);
        $theirs = $this->createSessionRow(User::factory()->create());

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->delete(self::SCREEN)
            ->assertRedirect(self::SCREEN)
            ->assertSessionHas('toast.type', 'success');

        $this->assertFalse(DB::table('sessions')->where('id', $first)->exists());
        $this->assertFalse(DB::table('sessions')->where('id', $second)->exists());
        $this->assertTrue(DB::table('sessions')->where('id', $theirs)->exists());
    }

    #[Test]
    public function revoking_others_when_there_are_none_is_reported_not_an_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->delete(self::SCREEN)
            ->assertRedirect(self::SCREEN)
            ->assertSessionHas('toast.type', 'info');
    }

    /**
     * Revoking the rest must not sign the current device out — that is what the logout button is
     * for, and it is also the button that writes the history row.
     */
    #[Test]
    public function revoking_others_leaves_the_current_session_signed_in(): void
    {
        $user = User::factory()->create();
        $this->createSessionRow($user);

        $this->actingAs($user)->from(self::SCREEN)->delete(self::SCREEN);

        $this->assertAuthenticatedAs($user);
        $this->actingAs($user)->get(self::SCREEN)->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Own login history
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_login_history_tab_shows_only_the_users_own_rows(): void
    {
        $me = User::factory()->create(['email' => 'mine@example.test']);
        $someoneElse = User::factory()->create(['email' => 'theirs@example.test']);

        $this->post('/login', ['email' => $me->email, 'password' => 'password']);
        $this->post('/logout');
        $this->post('/login', ['email' => $someoneElse->email, 'password' => 'password']);
        $this->post('/logout');

        $response = $this->actingAs($me)->get('/account/login-history');

        $response->assertOk();
        $response->assertSee('mine@example.test');
        $response->assertDontSee('theirs@example.test');
    }
}
