<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\ThemePreference;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * `PUT /account/theme`, route name `account.theme.update` (phase-01 §9).
 *
 * The endpoint the UI shell posts to when the switcher is used, so the preference follows the user
 * to another device. Allowed values are the ThemePreference cases and nothing else.
 */
final class ThemeTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /**
     * @return array<string, array{0: string, 1: ThemePreference}>
     */
    public static function themeProvider(): array
    {
        return [
            'light' => ['light', ThemePreference::Light],
            'dark' => ['dark', ThemePreference::Dark],
            'system' => ['system', ThemePreference::System],
        ];
    }

    #[Test]
    public function the_theme_route_is_registered_under_the_contracted_name(): void
    {
        $this->assertSame('/account/theme', route('account.theme.update', absolute: false));
    }

    #[Test]
    #[DataProvider('themeProvider')]
    public function a_theme_preference_is_persisted(string $value, ThemePreference $expected): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);

        $this->actingAs($user)
            ->putJson('/account/theme', ['theme' => $value])
            ->assertOk()
            ->assertExactJson(['theme' => $value, 'label' => $expected->label()]);

        $this->assertSame($expected, $user->fresh()->theme);
    }

    #[Test]
    public function the_preference_survives_the_next_request(): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);

        $this->actingAs($user)->putJson('/account/theme', ['theme' => 'dark'])->assertOk();

        // A second, unrelated request must still see the stored preference.
        $this->actingAs($user->fresh())->get('/account/profile')->assertOk();

        $this->assertSame(ThemePreference::Dark, $user->fresh()->theme);
    }

    /**
     * Without JavaScript the switcher is a plain form post, which must still work.
     */
    #[Test]
    public function a_plain_form_post_is_answered_with_a_redirect_and_a_toast(): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->put('/account/theme', ['theme' => 'light'])
            ->assertRedirect('/account/profile')
            ->assertSessionHas('toast.type', 'success');

        $this->assertSame(ThemePreference::Light, $user->fresh()->theme);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unknown_theme_is_rejected(): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);

        $this->actingAs($user)
            ->putJson('/account/theme', ['theme' => 'neon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme');

        $this->assertSame(ThemePreference::System, $user->fresh()->theme);
    }

    #[Test]
    public function a_missing_theme_is_rejected(): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);

        $this->actingAs($user)
            ->putJson('/account/theme', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme');

        $this->assertSame(ThemePreference::System, $user->fresh()->theme);
    }

    #[Test]
    public function a_non_string_theme_is_rejected(): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);

        $this->actingAs($user)
            ->putJson('/account/theme', ['theme' => ['dark']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme');
    }

    #[Test]
    public function a_guest_cannot_set_a_theme(): void
    {
        $this->put('/account/theme', ['theme' => 'dark'])
            ->assertRedirect(route('login', absolute: false));
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation and noise
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function setting_a_theme_only_ever_writes_the_authenticated_user(): void
    {
        $me = User::factory()->create(['theme' => ThemePreference::System]);
        $someoneElse = User::factory()->create(['theme' => ThemePreference::Light]);

        $this->actingAs($me)->putJson('/account/theme', [
            'theme' => 'dark',
            'user_id' => $someoneElse->getKey(),
        ])->assertOk();

        $this->assertSame(ThemePreference::Dark, $me->fresh()->theme);
        $this->assertSame(ThemePreference::Light, $someoneElse->fresh()->theme);
    }

    /**
     * The switcher can be used several times a minute; an audit row for each would be noise, so
     * the controller saves quietly (phase-01 §9).
     */
    #[Test]
    public function a_theme_change_is_not_an_audit_event(): void
    {
        $user = User::factory()->create(['theme' => ThemePreference::System]);
        $before = Activity::query()->count();

        $this->actingAs($user)->putJson('/account/theme', ['theme' => 'dark'])->assertOk();

        $this->assertSame($before, Activity::query()->count());
    }
}
