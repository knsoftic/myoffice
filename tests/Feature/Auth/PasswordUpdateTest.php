<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A password that satisfies App\Services\Auth\PasswordPolicy (10+ chars, mixed case,
     * a number and a symbol).
     */
    private const NEW_PASSWORD = 'Str0ng!Passw0rd';

    /**
     * The change-password screen lives at /account/password (phase-01 §7); Breeze's
     * PUT /password endpoint is kept as the action and redirects back to it.
     */
    private const SCREEN = '/account/password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCompromisedPasswordCheck();
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(self::SCREEN)
            ->put('/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::SCREEN);

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertFalse($user->must_change_password);
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(self::SCREEN)
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(self::SCREEN);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /**
     * PasswordPolicy uses `uncompromised()`, which calls the haveibeenpwned range API. Stub the
     * verifier so the suite never depends on the network — the rest of the rule set still runs.
     */
    private function withoutCompromisedPasswordCheck(): void
    {
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });
    }
}
