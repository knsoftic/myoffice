<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Login throttling (phase-01 §7: "throttle by email + IP").
 *
 * `LoginRequest` allows five attempts per email + IP before the lockout. The key is the pair, so
 * hammering one account must not lock a different one out from the same address.
 */
final class LoginThrottleTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** LoginRequest::MAX_ATTEMPTS. */
    private const MAX_ATTEMPTS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        RateLimiter::clear($this->throttleKey('anything@example.test'));
    }

    #[Test]
    public function the_sixth_wrong_password_is_throttled_rather_than_answered(): void
    {
        Event::fake([Lockout::class]);

        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->from('/login')
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');

            $this->assertSame(
                trans('auth.failed'),
                (string) session('errors')->first('email'),
                sprintf('Attempt %d must still be answered with the generic failure.', $attempt)
            );
        }

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many login attempts',
            (string) session('errors')->first('email'),
            'The attempt after the limit must be refused by the throttle, not by the credentials.'
        );

        Event::assertDispatched(Lockout::class);
        $this->assertGuest();
    }

    #[Test]
    public function the_throttle_blocks_the_correct_password_too_while_it_is_active(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest('web');
        $this->assertStringContainsString('Too many login attempts', (string) session('errors')->first('email'));
    }

    /**
     * The key is email + IP, so a locked-out address must not lock every account on it.
     */
    #[Test]
    public function the_throttle_is_scoped_to_the_email_and_ip_pair(): void
    {
        $victim = User::factory()->create();
        $bystander = User::factory()->create();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS + 1; $attempt++) {
            $this->post('/login', ['email' => $victim->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', [
            'email' => $bystander->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($bystander);
    }

    #[Test]
    public function a_successful_sign_in_clears_the_attempt_counter(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS - 1; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);

        $this->assertSame(
            0,
            RateLimiter::attempts($this->throttleKey((string) $user->email)),
            'A successful sign-in must reset the throttle for that email + IP.'
        );
    }

    /**
     * Mirrors LoginRequest::throttleKey().
     */
    private function throttleKey(string $email): string
    {
        return Str::transliterate(Str::lower($email).'|127.0.0.1');
    }
}
