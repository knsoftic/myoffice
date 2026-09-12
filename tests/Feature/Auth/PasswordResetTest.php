<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Password reset, end to end (phase-01 §7, §10 "Auth: password reset flow").
 *
 * Request a link → open the screen the link points at → set a new password → sign in with it.
 * Along the way: the token is single-use, a tampered token is refused, and the reset may not slip
 * a password past App\Services\Auth\PasswordPolicy — the rules the account screen enforces.
 */
final class PasswordResetTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Satisfies PasswordPolicy: 10+ characters, mixed case, a number and a symbol. */
    private const NEW_PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->withoutCompromisedPasswordCheck();
    }

    #[Test]
    public function the_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    #[Test]
    public function a_reset_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function requesting_a_link_for_an_unknown_address_reports_a_validation_error(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    /**
     * The whole flow in one test: link → screen → new password → sign in with it.
     */
    #[Test]
    public function a_password_can_be_reset_from_the_emailed_link_and_used_to_sign_in(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        $token = $this->capturedToken($user);

        $this->get('/reset-password/'.$token)->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->password));
        $this->assertNotNull($user->password_changed_at, 'A reset must stamp password_changed_at.');
        $this->assertFalse(
            (bool) $user->must_change_password,
            'Choosing the password yourself satisfies a pending forced change.'
        );

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_reset_token_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->capturedToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', $payload)
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function a_tampered_token_is_refused(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $this->from('/reset-password/not-a-real-token')
            ->post('/reset-password', [
                'token' => 'not-a-real-token',
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    /**
     * The reset screen is not a back door around the password rules.
     */
    #[Test]
    public function a_reset_must_satisfy_the_password_policy(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->capturedToken($user);

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'weakpass',
                'password_confirmation' => 'weakpass',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    #[Test]
    public function a_reset_must_be_confirmed(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->capturedToken($user);

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * Resetting is also how a password change revokes other devices: the stored session rows of
     * that user must not survive it.
     */
    #[Test]
    public function a_reset_revokes_the_stored_sessions_of_that_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $stale = $this->createSessionRow($user);

        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->capturedToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertFalse(
            DB::table('sessions')->where('id', $stale)->exists(),
            'A password reset must end the sessions opened with the old credentials.'
        );
    }

    /**
     * The plain token only exists inside the notification, so that is where the test reads it —
     * exactly like a user clicking the link in the email.
     */
    private function capturedToken(User $user): string
    {
        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertIsString($token, 'No reset token was delivered.');

        return $token;
    }
}
