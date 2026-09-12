<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\LoginStatus;
use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Sign in / sign out (phase-01 §7, §10 "Auth").
 *
 * Covers the happy path and every way the contract says a sign-in must be refused: wrong
 * credentials, and an account whose `UserStatus` may not log in (inactive, suspended, pending).
 * A refusal has to leave no session behind and has to say which state the account is in.
 */
final class AuthenticationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | Screens
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    #[Test]
    public function self_registration_does_not_exist(): void
    {
        // phase-01 §7 / D15: Breeze's register routes and views are removed.
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_can_sign_in_and_lands_on_their_own_panel_home(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        // A factory user holds no role, so primaryPanel() falls back to the staff panel.
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    /**
     * Every panel role lands on its own home route, proving the redirect is driven by
     * `primaryPanel()->homeRoute()` and not by a hardcoded destination.
     */
    #[Test]
    #[DataProvider('panelRoleProvider')]
    public function a_panel_role_lands_on_its_own_home_route(string $role, string $routeName): void
    {
        $user = $this->createUserWithRole($role);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route($routeName, absolute: false));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function panelRoleProvider(): array
    {
        return [
            'admin' => ['Admin', 'admin.dashboard'],
            'student' => ['Student', 'student.dashboard'],
            'teacher' => ['Teacher', 'teacher.dashboard'],
            'client' => ['Client', 'client.dashboard'],
            'collaborator' => ['Collaborator', 'collaborator.dashboard'],
        ];
    }

    #[Test]
    public function signing_in_remembers_the_device_when_asked(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    #[Test]
    public function a_successful_sign_in_stamps_the_last_login_columns(): void
    {
        $user = User::factory()->create(['last_login_at' => null, 'last_login_ip' => null]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $user->refresh();

        $this->assertNotNull($user->last_login_at, 'users.last_login_at must be stamped on sign-in.');
        $this->assertSame('127.0.0.1', $user->last_login_ip);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertSame(
            trans('auth.failed'),
            session('errors')->first('email'),
            'A wrong password must not reveal whether the account exists.'
        );
    }

    #[Test]
    public function an_unknown_email_is_refused_with_the_same_message_as_a_wrong_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $this->assertSame(trans('auth.failed'), session('errors')->first('email'));
    }

    /**
     * Correct credentials, wrong account state: no session may survive, and the error names the
     * state (phase-01 §7).
     */
    #[Test]
    #[DataProvider('blockedStatusProvider')]
    public function an_account_that_may_not_log_in_is_refused(string $factoryState, string $expectedFragment): void
    {
        $user = User::factory()->{$factoryState}()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            $expectedFragment,
            (string) session('errors')->first('email'),
            'The refusal must name the state the account is in.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedStatusProvider(): array
    {
        return [
            'inactive' => ['inactive', 'inactive'],
            'suspended' => ['suspended', 'suspended'],
            'pending' => ['pending', 'pending approval'],
        ];
    }

    #[Test]
    public function a_blocked_account_writes_a_blocked_login_history_row_and_no_success_row(): void
    {
        $user = User::factory()->suspended()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $rows = LoginHistory::query()->where('user_id', $user->getKey())->get();

        $this->assertTrue(
            $rows->contains(fn (LoginHistory $row): bool => $row->status === LoginStatus::Blocked),
            'A refused-by-status sign-in must leave a `blocked` row.'
        );

        $this->assertFalse(
            $rows->contains(fn (LoginHistory $row): bool => $row->status === LoginStatus::Success),
            'The history must never claim a sign-in that was refused.'
        );
    }

    /**
     * Suspending an account must take effect immediately, not only at the next sign-in
     * (the `active` middleware runs on every request).
     */
    #[Test]
    public function a_session_is_torn_down_when_the_account_is_suspended_mid_session(): void
    {
        $user = $this->createUserWithRole('Admin');

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->forceFill(['status' => UserStatus::Suspended])->saveQuietly();

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Sign out
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_can_sign_out(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();

        // There is no public application to return to (the `/` holding page is replaced by the
        // CMS in Phase 3), so signing out lands on the login screen.
        $response->assertRedirect(route('login', absolute: false));
    }

    #[Test]
    public function a_guest_cannot_reach_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect(route('login', absolute: false));
    }
}
