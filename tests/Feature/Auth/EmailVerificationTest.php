<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Email verification, end to end (phase-01 §7, §10 "Auth: email verification flow").
 *
 * Prompt → resend the notification → follow the signed link → land on the user's own panel home
 * with `?verified=1`. A link with the wrong hash, an unsigned link and a guest are all refused.
 */
final class EmailVerificationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function the_verification_prompt_renders_for_an_unverified_user(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/verify-email')->assertOk();
    }

    #[Test]
    public function an_already_verified_user_is_sent_to_their_panel_home(): void
    {
        $user = $this->createUserWithRole('Student');

        $this->assertTrue($user->hasVerifiedEmail());

        $this->actingAs($user)
            ->get('/verify-email')
            ->assertRedirect(route('student.dashboard', absolute: false));
    }

    #[Test]
    public function a_guest_cannot_reach_the_verification_prompt(): void
    {
        $this->get('/verify-email')->assertRedirect(route('login', absolute: false));
    }

    #[Test]
    public function the_verification_notification_can_be_resent(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->from('/verify-email')
            ->post('/email/verification-notification')
            ->assertRedirect('/verify-email');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function the_emailed_link_verifies_the_address_and_lands_on_the_panel_home(): void
    {
        Event::fake([Verified::class]);

        $user = $this->createUserWithRole('Teacher', ['email_verified_at' => null]);

        $this->assertFalse($user->hasVerifiedEmail());

        $response = $this->actingAs($user)->get($this->verificationUrl($user));

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('teacher.dashboard', absolute: false).'?verified=1');
    }

    #[Test]
    public function a_link_with_the_wrong_hash_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1('wrong-email')],
        );

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function an_unsigned_link_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/verify-email/'.$user->getKey().'/'.sha1((string) $user->email))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function following_the_link_twice_does_not_re_fire_the_event(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user);

        $this->actingAs($user)->get($url);
        $this->actingAs($user->fresh())->get($url);

        Event::assertDispatchedTimes(Verified::class, 1);
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1((string) $user->email)],
        );
    }
}
