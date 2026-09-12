<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\PanelType;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-01 §10 "Panel isolation": a Student hitting /admin, /collaborator, /teacher and /client
 * gets 403 — and the same for every other panel role.
 *
 * The whole 5 × 5 matrix is asserted: each demo account against all five panel homes, with exactly
 * one allowed. One identity, five doors, and the door is chosen by the `panel` column of the roles
 * the user holds (`EnsurePanelAccess` → `User::canAccessPanel()`), never by rank.
 */
final class PanelIsolationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The five panel entry points, keyed by the panel they belong to. */
    private const PANEL_HOMES = [
        'admin' => '/admin',
        'collaborator' => '/collaborator',
        'student' => '/student',
        'teacher' => '/teacher',
        'client' => '/client',
    ];

    /**
     * The seeded demo account for each panel (DemoUserSeeder) and the panel it owns.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function panelAccountProvider(): array
    {
        return [
            'admin' => ['Admin', 'admin'],
            'collaborator' => ['Collaborator', 'collaborator'],
            'student' => ['Student', 'student'],
            'teacher' => ['Teacher', 'teacher'],
            'client' => ['Client', 'client'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | The matrix
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('panelAccountProvider')]
    public function a_panel_account_reaches_exactly_one_panel(string $role, string $ownPanel): void
    {
        $user = $this->seededDemoUser($role);

        $allowed = [];

        foreach (self::PANEL_HOMES as $panel => $path) {
            $status = $this->actingAs($user)->get($path)->getStatusCode();

            if ($status === 200) {
                $allowed[] = $panel;

                continue;
            }

            $this->assertSame(
                403,
                $status,
                sprintf('%s must be refused %s with 403, got %d.', (string) $user->email, $path, $status)
            );
        }

        $this->assertSame(
            [$ownPanel],
            $allowed,
            sprintf('%s must reach exactly one panel home.', (string) $user->email)
        );
    }

    #[Test]
    #[DataProvider('panelAccountProvider')]
    public function can_access_panel_agrees_with_the_http_matrix(string $role, string $ownPanel): void
    {
        $user = $this->seededDemoUser($role);

        foreach (PanelType::cases() as $panel) {
            $this->assertSame(
                $panel->value === $ownPanel,
                $user->canAccessPanel($panel),
                sprintf('%s: canAccessPanel(%s) disagrees with the panel matrix.', $role, $panel->value)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Edge cases
    |--------------------------------------------------------------------------
    */

    /**
     * Holding two panel roles opens both doors — and still nothing else.
     */
    #[Test]
    public function a_user_holding_two_panel_roles_reaches_both_of_them(): void
    {
        $user = $this->createUserWithRole('Student');
        $user->assignRole('Teacher');
        $this->forgetPermissionCache();
        $user = $user->fresh();

        $this->actingAs($user)->get('/student')->assertOk();
        $this->actingAs($user)->get('/teacher')->assertOk();

        foreach (['/admin', '/client', '/collaborator'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    /**
     * A user with no role at all has no panel. `primaryPanel()` falls back to the staff panel for
     * the purpose of *redirecting* them somewhere, but `canAccessPanel()` must still say no — so
     * every door is closed.
     */
    #[Test]
    public function a_user_with_no_role_reaches_no_panel(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->panels()->isEmpty());

        foreach (self::PANEL_HOMES as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    #[Test]
    public function a_guest_is_redirected_to_the_login_screen_from_every_panel(): void
    {
        foreach (self::PANEL_HOMES as $path) {
            $this->get($path)->assertRedirect(route('login', absolute: false));
        }
    }

    /**
     * Panel access is not rank: the most powerful role in the system does not get a student's
     * dashboard (phase-01 §3 — "Super Admin does not get a free pass into the student panel").
     */
    #[Test]
    public function rank_does_not_open_another_panel(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->actingAs($superAdmin)->get('/admin')->assertOk();

        foreach (['/student', '/teacher', '/client', '/collaborator'] as $path) {
            $this->actingAs($superAdmin)->get($path)->assertForbidden();
        }
    }

    /**
     * Every panel home route the PanelType enum promises has to exist, or the post-login redirect
     * silently degrades to a fallback.
     */
    #[Test]
    public function every_panel_type_has_a_registered_home_route(): void
    {
        foreach (PanelType::cases() as $panel) {
            $this->assertTrue(
                Route::has($panel->homeRoute()),
                sprintf('%s::homeRoute() names %s, which is not registered.', $panel->name, $panel->homeRoute())
            );

            $this->assertSame(
                self::PANEL_HOMES[$panel->value],
                route($panel->homeRoute(), absolute: false),
                sprintf('%s must be served from %s.', $panel->name, self::PANEL_HOMES[$panel->value])
            );
        }
    }

    /**
     * A suspended account loses its panel immediately, whatever role it holds.
     */
    #[Test]
    public function a_blocked_account_reaches_no_panel_even_its_own(): void
    {
        $user = $this->seededDemoUser('Student');
        $user->forceFill(['status' => UserStatus::Suspended])->saveQuietly();

        $this->actingAs($user)
            ->get('/student')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }
}
