<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Changing your own password (phase-01 §7, §10 "Profile": change password invalidates other
 * sessions).
 *
 * The side effects are the point: `password_changed_at` stamped, `must_change_password` cleared,
 * the remember token rotated so every old "remember me" cookie dies, and the user's other
 * `sessions` rows deleted — while the session making the request survives.
 */
final class PasswordChangeTest extends TestCase
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
    public function the_change_password_screen_renders(): void
    {
        $user = $this->createUserWithPermissions([]);

        $this->actingAs($user)->get(self::SCREEN)->assertOk();
    }

    #[Test]
    public function a_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create(['password_changed_at' => null]);
        $oldToken = (string) $user->remember_token;

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::SCREEN)
            ->assertSessionHas('toast.type', 'success');

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertNotSame(
            $oldToken,
            (string) $user->remember_token,
            'Rotating the remember token is what kills the "remember me" cookies issued before.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Other sessions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_the_password_revokes_the_other_sessions_of_that_user(): void
    {
        $user = User::factory()->create();

        $otherDevice = $this->createSessionRow($user);
        $thirdDevice = $this->createSessionRow($user);
        $someoneElse = $this->createSessionRow(User::factory()->create());

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(DB::table('sessions')->where('id', $otherDevice)->exists());
        $this->assertFalse(DB::table('sessions')->where('id', $thirdDevice)->exists());

        $this->assertTrue(
            DB::table('sessions')->where('id', $someoneElse)->exists(),
            'Another user\'s session is none of this request\'s business.'
        );
    }

    #[Test]
    public function the_session_making_the_request_survives_the_change(): void
    {
        $user = User::factory()->create();
        $this->createSessionRow($user);

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);

        // Exactly one row left: the one this request is using.
        $this->assertSame(
            1,
            DB::table('sessions')->where('user_id', $user->getKey())->count(),
            'Changing your password must leave your current device signed in and nothing else.'
        );

        $this->actingAs($user)->get(self::SCREEN)->assertOk();
    }

    #[Test]
    public function the_new_password_can_be_used_to_sign_in_and_the_old_one_cannot(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD]);
        $this->assertAuthenticatedAs($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_current_password_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'wrong-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(self::SCREEN);

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    #[Test]
    public function the_new_password_must_differ_from_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function the_new_password_must_satisfy_the_policy(): void
    {
        $user = User::factory()->create();

        foreach (['short1!A', 'alllowercase1!', 'NOUPPERLOWER1!', 'NoSymbols12345', 'NoNumbers!!!!'] as $weak) {
            $this->actingAs($user)
                ->from(self::SCREEN)
                ->put('/account/password', [
                    'current_password' => 'password',
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ])
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    #[Test]
    public function the_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(self::SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }

    #[Test]
    public function a_guest_cannot_change_a_password(): void
    {
        $this->put('/account/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login', absolute: false));
    }

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_password_change_is_written_to_the_activity_log(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $entry = Activity::query()->where('event', 'password_changed')->latest('id')->first();

        $this->assertNotNull($entry, 'A password change must leave an audit entry.');
        $this->assertSame($user->getKey(), $entry->causer_id);
        $this->assertSame('Password changed', $entry->description);
    }

    /**
     * The audit trail must never be able to leak the credential itself.
     */
    #[Test]
    public function the_activity_log_never_records_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $payload = Activity::query()->pluck('properties')->map(
            static fn (mixed $properties): string => json_encode($properties) ?: ''
        )->implode(' ');

        // `password` and `remember_token` are in LogsActivityWithContext::activitySecretAttributes(),
        // so neither the plain text nor the hash may appear anywhere in the trail. (The timestamp
        // `password_changed_at` is logged on purpose — it is metadata, not a secret.)
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $payload);
        $this->assertStringNotContainsString((string) $user->fresh()->password, $payload);
        $this->assertStringNotContainsString('"password"', $payload);
    }
}
