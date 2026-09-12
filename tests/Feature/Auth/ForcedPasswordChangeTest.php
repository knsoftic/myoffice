<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * `users.must_change_password` (phase-01 §6 "active" middleware, §7).
 *
 * While the flag is set, the change-password screen is the only thing the account can reach —
 * signing in lands there, every panel and every other account screen bounces back to it, and the
 * flag is cleared only by actually changing the password.
 */
final class ForcedPasswordChangeTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Satisfies App\Services\Auth\PasswordPolicy. */
    private const NEW_PASSWORD = 'Str0ng!Passw0rd';

    private const SCREEN = '/account/password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->withoutCompromisedPasswordCheck();
    }

    #[Test]
    public function signing_in_with_a_pending_change_lands_on_the_change_password_screen(): void
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(self::SCREEN);
    }

    #[Test]
    public function a_pending_change_pins_the_account_to_the_change_password_screen(): void
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        // The screen itself stays open...
        $this->actingAs($user)->get(self::SCREEN)->assertOk();

        // ...and everything else bounces back to it, panels and account screens alike.
        foreach (['/admin', '/admin/users', '/account/profile', '/account/sessions'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertRedirect(self::SCREEN);
        }
    }

    #[Test]
    public function the_theme_endpoint_and_logout_stay_reachable_while_a_change_is_pending(): void
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        // A user who cannot change the theme cannot dismiss the dark/light flash; a user who
        // cannot log out is trapped. Both are on the `active` middleware's allow list.
        $this->actingAs($user)
            ->putJson('/account/theme', ['theme' => 'dark'])
            ->assertOk();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login', absolute: false));
        $this->assertGuest();
    }

    #[Test]
    public function changing_the_password_clears_the_flag_and_releases_the_account(): void
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $response = $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $response->assertSessionHasNoErrors();

        // A forced change hands the user straight to their panel home rather than back to the form.
        $response->assertRedirect(route('admin.dashboard', absolute: false));

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->password));

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    #[Test]
    public function the_flag_survives_a_rejected_password_change(): void
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'wrong-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    #[Test]
    public function an_administrator_issued_temporary_password_forces_the_change(): void
    {
        $admin = $this->createSuperAdmin();
        $target = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($admin)
            ->post('/admin/users/'.$target->getKey().'/reset-password', ['reason' => 'Lost phone'])
            ->assertRedirect('/admin/users/'.$target->getKey());

        $this->assertTrue(
            $target->fresh()->must_change_password,
            'A reset issued by an administrator must force a change on next sign-in.'
        );
    }
}
