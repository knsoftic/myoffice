<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The user's own profile (phase-01 §7: name, phone, whatsapp, locale, timezone).
 *
 * What matters as much as the happy path: email, status, roles and branch are **not** writable
 * here. Those are administrative fields owned by the users module, so posting extra keys to this
 * endpoint must change nothing — a privilege-escalation test, not a validation nicety.
 */
final class ProfileTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function the_profile_screen_renders(): void
    {
        $user = $this->createUserWithPermissions([]);

        $this->actingAs($user)->get('/account/profile')->assertOk();
    }

    #[Test]
    public function a_guest_cannot_reach_the_profile_screen(): void
    {
        $this->get('/account/profile')->assertRedirect(route('login', absolute: false));
    }

    #[Test]
    public function a_user_can_update_their_own_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'phone' => null,
            'whatsapp' => null,
            'locale' => 'en',
            'timezone' => null,
        ]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->put('/account/profile', [
                'name' => 'New Name',
                'phone' => '+92 300 1234567',
                'whatsapp' => '+92 301 7654321',
                'locale' => 'ur',
                'timezone' => 'Asia/Karachi',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/account/profile')
            ->assertSessionHas('toast.type', 'success');

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('+92 300 1234567', $user->phone);
        $this->assertSame('+92 301 7654321', $user->whatsapp);
        $this->assertSame('ur', $user->locale);
        $this->assertSame('Asia/Karachi', $user->timezone);
    }

    #[Test]
    public function blank_optional_fields_are_stored_as_null_rather_than_an_empty_string(): void
    {
        $user = User::factory()->create(['phone' => '+92 300 1111111', 'whatsapp' => '+92 300 2222222']);

        $this->actingAs($user)->put('/account/profile', [
            'name' => $user->name,
            'phone' => '',
            'whatsapp' => '  ',
            'locale' => 'en',
            'timezone' => '',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNull($user->phone);
        $this->assertNull($user->whatsapp);
        $this->assertNull($user->timezone);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidProfileProvider(): array
    {
        return [
            'missing name' => [['name' => '', 'locale' => 'en'], 'name'],
            'one-character name' => [['name' => 'A', 'locale' => 'en'], 'name'],
            'missing locale' => [['name' => 'Valid Name', 'locale' => ''], 'locale'],
            'unknown locale' => [['name' => 'Valid Name', 'locale' => 'xx'], 'locale'],
            'letters in phone' => [['name' => 'Valid Name', 'locale' => 'en', 'phone' => 'call me'], 'phone'],
            'letters in whatsapp' => [['name' => 'Valid Name', 'locale' => 'en', 'whatsapp' => 'ping me'], 'whatsapp'],
            'unknown timezone' => [['name' => 'Valid Name', 'locale' => 'en', 'timezone' => 'Mars/Olympus'], 'timezone'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[Test]
    #[DataProvider('invalidProfileProvider')]
    public function the_profile_form_is_validated(array $payload, string $expectedError): void
    {
        $user = User::factory()->create(['name' => 'Untouched Name']);

        $this->actingAs($user)
            ->from('/account/profile')
            ->put('/account/profile', $payload)
            ->assertSessionHasErrors($expectedError);

        $this->assertSame('Untouched Name', $user->fresh()->name);
    }

    /*
    |--------------------------------------------------------------------------
    | The fields this endpoint must refuse to touch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_profile_endpoint_cannot_change_the_email_status_or_branch(): void
    {
        $user = User::factory()->create([
            'email' => 'me@example.test',
            'status' => UserStatus::Active,
            'branch_id' => null,
        ]);

        $this->actingAs($user)->put('/account/profile', [
            'name' => 'Still Me',
            'locale' => 'en',
            // Everything below is administrative and must be ignored.
            'email' => 'promoted@example.test',
            'status' => UserStatus::Suspended->value,
            'branch_id' => 1,
            'must_change_password' => true,
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('me@example.test', $user->email);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNull($user->branch_id);
        $this->assertFalse((bool) $user->must_change_password);
    }

    #[Test]
    public function the_profile_endpoint_cannot_grant_a_role(): void
    {
        $user = $this->createUserWithPermissions([]);
        $before = $user->roles->pluck('name')->all();

        $this->actingAs($user)->put('/account/profile', [
            'name' => 'Still Me',
            'locale' => 'en',
            'roles' => [1],
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $user->fresh()->roles->pluck('name')->all());
    }

    /**
     * One user editing their profile must not be able to touch another row.
     */
    #[Test]
    public function the_profile_endpoint_only_ever_writes_the_authenticated_user(): void
    {
        $me = User::factory()->create(['name' => 'Me']);
        $someoneElse = User::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($me)->put('/account/profile', [
            'name' => 'Renamed',
            'locale' => 'en',
            'id' => $someoneElse->getKey(),
            'user_id' => $someoneElse->getKey(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $me->fresh()->name);
        $this->assertSame('Someone Else', $someoneElse->fresh()->name);
    }
}
