<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1 integration smoke test.
 *
 * Proves that the thirteen parallel work streams actually compose into a bootable application:
 * the public entry points render, the five panel entry points exist and are isolated from one
 * another, the seeded accounts can reach exactly what their role allows, a blocked account
 * cannot authenticate, and the module gate closes routes for everyone — Super Admin included.
 *
 * Everything here runs against the real seeded data (`DatabaseSeeder`), never against
 * hand-built fixtures, so a registry / seeder / route mismatch fails the test instead of
 * hiding behind a factory.
 */
final class SmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This class is meaningless without the real install sequence (modules, permissions, the 18
     * roles, settings, the Super Admin and the demo accounts). `Tests\TestCase::$seed` asks
     * RefreshDatabase to seed, but that only happens on the single `migrate:fresh` per test
     * process — so verify it actually landed and seed on demand if another class consumed it.
     * Keeps the class correct whatever order the suite runs in.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $seeded = User::query()
            ->where('email', SuperAdminSeeder::DEFAULT_EMAIL)
            ->exists();

        if (! $seeded) {
            $this->seed(DatabaseSeeder::class);
        }
    }

    /**
     * Every Phase-1 screen a Super Admin must be able to open.
     *
     * @var array<string, string>
     */
    private const SUPER_ADMIN_SCREENS = [
        'admin dashboard' => '/admin',
        'users index' => '/admin/users',
        'user create' => '/admin/users/create',
        'roles index' => '/admin/roles',
        'role create' => '/admin/roles/create',
        'permissions index' => '/admin/permissions',
        'modules index' => '/admin/modules',
        'activity-log index' => '/admin/activity-log',
        'login-history index' => '/admin/login-history',
        'account profile' => '/account/profile',
        'account sessions' => '/account/sessions',
    ];

    /** The five panel entry points, keyed by the panel they belong to. */
    private const PANEL_HOMES = [
        'admin' => '/admin',
        'collaborator' => '/collaborator',
        'student' => '/student',
        'teacher' => '/teacher',
        'client' => '/client',
    ];

    /*
    |--------------------------------------------------------------------------
    | Public surface
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_public_home_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    #[Test]
    public function the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    #[Test]
    public function self_registration_does_not_exist(): void
    {
        // phase-01 §7/D15: Breeze's register routes and views are removed.
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    #[Test]
    public function guests_are_redirected_from_the_admin_panel_to_the_login_screen(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_super_admin_can_open_every_phase_one_screen(): void
    {
        $superAdmin = $this->superAdmin();

        foreach (self::SUPER_ADMIN_SCREENS as $label => $path) {
            $response = $this->actingAs($superAdmin)->get($path);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    'Expected the %s (%s) to render for the Super Admin, got %d.',
                    $label,
                    $path,
                    $response->getStatusCode()
                )
            );
        }
    }

    #[Test]
    public function every_remaining_phase_one_admin_screen_renders(): void
    {
        $superAdmin = $this->superAdmin();
        $user = $this->demoUser('Admin');
        $role = Role::query()->where('name', 'Admin')->firstOrFail();
        $activity = Activity::query()->latest('id')->first();

        $paths = [
            '/admin/users/'.$user->getKey(),
            '/admin/users/'.$user->getKey().'/edit',
            '/admin/roles/'.$role->getKey(),
            '/admin/roles/'.$role->getKey().'/edit',
            '/account/password',
            '/account/login-history',
            // Search / filter / sort are part of every list view (CLAUDE.md §6).
            '/admin/users?search=demo&status=active&sort=name&direction=desc',
            '/admin/roles?search=admin',
            '/admin/permissions?search=users&module=users',
            '/admin/modules?search=collaborators',
            '/admin/activity-log?search=demo',
            '/admin/login-history?search=demo',
            '/admin/activity-log/export',
            '/admin/login-history/export',
        ];

        if ($activity !== null) {
            $paths[] = '/admin/activity-log/'.$activity->getKey();
        }

        foreach ($paths as $path) {
            $response = $this->actingAs($superAdmin)->get($path);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf('Expected %s to render, got %d.', $path, $response->getStatusCode())
            );
        }
    }

    #[Test]
    public function a_system_role_resists_rename_and_delete(): void
    {
        $superAdmin = $this->superAdmin();
        $system = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $this->assertTrue($system->isProtected());

        $this->actingAs($superAdmin)->put('/admin/roles/'.$system->getKey(), [
            'name' => 'Renamed Super Admin',
            'panel' => 'admin',
            'level' => 1,
        ]);

        $this->actingAs($superAdmin)->delete('/admin/roles/'.$system->getKey());

        $survivor = Role::query()->whereKey($system->getKey())->first();

        $this->assertNotNull($survivor, 'An is_system role must not be deletable.');
        $this->assertSame(User::SUPER_ADMIN_ROLE, (string) $survivor->name, 'An is_system role must not be renamable.');
    }

    /*
    |--------------------------------------------------------------------------
    | Panel isolation (phase-01 §10)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_student_account_reaches_only_the_student_panel(): void
    {
        $this->assertPanelIsolation($this->demoUser('Student'), 'student');
    }

    #[Test]
    public function the_collaborator_account_reaches_only_the_collaborator_panel(): void
    {
        $this->assertPanelIsolation($this->demoUser('Collaborator'), 'collaborator');
    }

    #[Test]
    public function the_teacher_account_reaches_only_the_teacher_panel(): void
    {
        $this->assertPanelIsolation($this->demoUser('Teacher'), 'teacher');
    }

    #[Test]
    public function the_client_account_reaches_only_the_client_panel(): void
    {
        $this->assertPanelIsolation($this->demoUser('Client'), 'client');
    }

    /*
    |--------------------------------------------------------------------------
    | Account state
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_suspended_account_cannot_sign_in(): void
    {
        $user = $this->demoUser('Support Agent');

        $user->forceFill([
            'status' => UserStatus::Suspended,
            'status_reason' => 'Smoke test',
            'status_changed_at' => now(),
        ])->saveQuietly();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => DemoUserSeeder::DEMO_PASSWORD,
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    #[Test]
    public function a_pending_password_change_pins_the_account_to_the_change_password_screen(): void
    {
        // The seeded first account is flagged `must_change_password` (phase-01 §7).
        $user = User::query()
            ->where('email', SuperAdminSeeder::DEFAULT_EMAIL)
            ->firstOrFail();

        $this->assertTrue($user->mustChangePassword());

        $this->actingAs($user)->get('/account/password')->assertOk();
        $this->actingAs($user)->get('/admin')->assertRedirect('/account/password');

        // The rest of the account area is closed too — not just the panels.
        $this->actingAs($user)->get('/account/profile')->assertRedirect('/account/password');
        $this->actingAs($user)->get('/account/sessions')->assertRedirect('/account/password');
    }

    /*
    |--------------------------------------------------------------------------
    | Module gating (phase-01 §6, D5)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function disabling_a_non_core_module_closes_its_routes_for_the_super_admin(): void
    {
        // The one Phase-1 route group guarded by `module:` is the collaborator panel
        // (`module:collaborators`). Give the Super Admin a collaborator-panel role so the
        // panel check passes and the module gate is the only thing left deciding.
        $superAdmin = $this->superAdmin();
        $superAdmin->assignRole('Collaborator');
        $superAdmin = $superAdmin->fresh();

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->actingAs($superAdmin)->get('/collaborator')->assertOk();

        $this->setModuleEnabled('collaborators', false);

        $this->actingAs($superAdmin)->get('/collaborator')->assertForbidden();

        $this->setModuleEnabled('collaborators', true);

        $this->actingAs($superAdmin)->get('/collaborator')->assertOk();
    }

    #[Test]
    public function disabling_a_non_core_module_denies_its_abilities_to_the_super_admin(): void
    {
        $superAdmin = $this->superAdmin();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('projects.view_any'));

        $this->setModuleEnabled('projects', false);

        $this->assertTrue(
            Gate::forUser($superAdmin)->denies('projects.view_any'),
            'Gate::before must deny an ability of a disabled module to everyone, Super Admin included.'
        );

        $this->setModuleEnabled('projects', true);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('projects.view_any'));
    }

    #[Test]
    public function a_core_module_can_never_be_disabled(): void
    {
        // Even with the row flipped in the database, a core module stays on: the registry
        // decides, not the stored flag.
        Module::query()->where('slug', 'users')->update(['is_enabled' => false]);
        Module::query()->where('slug', 'users')->first()?->touch();

        $this->assertTrue(Module::enabled('users'));

        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin)->get('/admin/users')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The seeded Super Admin, past the forced first-sign-in password change.
     *
     * SuperAdminSeeder deliberately flags the account `must_change_password`, which the `active`
     * middleware enforces on every request (phase-01 §7). Clearing it here is the test standing
     * in for that first sign-in — it is a precondition, not a relaxed assertion.
     */
    private function superAdmin(): User
    {
        $user = User::query()
            ->where('email', SuperAdminSeeder::DEFAULT_EMAIL)
            ->firstOrFail();

        $user->forceFill([
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->saveQuietly();

        return $user->fresh();
    }

    /**
     * A seeded demo account, addressed by the role it was created for.
     */
    private function demoUser(string $role): User
    {
        $email = str($role)->slug()->value().'@'.DemoUserSeeder::DEMO_DOMAIN;

        return User::query()->where('email', $email)->firstOrFail();
    }

    /**
     * The user reaches their own panel and is refused every other one.
     */
    private function assertPanelIsolation(User $user, string $ownPanel): void
    {
        $this->assertArrayHasKey($ownPanel, self::PANEL_HOMES);

        foreach (self::PANEL_HOMES as $panel => $path) {
            if ($panel === $ownPanel) {
                $this->actingAs($user)->get($path)->assertOk();

                continue;
            }

            $this->assertPanelRefused($user, $path);
        }
    }

    /**
     * 403, or a redirect that lands somewhere other than the requested panel.
     */
    private function assertPanelRefused(User $user, string $path): void
    {
        $response = $this->actingAs($user)->get($path);
        $status = $response->getStatusCode();

        if ($response->isRedirection()) {
            $location = (string) $response->headers->get('Location');
            $target = parse_url($location, PHP_URL_PATH);

            $this->assertNotSame(
                $path,
                is_string($target) ? rtrim($target, '/') : null,
                sprintf('%s was redirected back to %s instead of away from it.', (string) $user->email, $path)
            );

            return;
        }

        $this->assertSame(
            403,
            $status,
            sprintf(
                '%s must be refused %s with 403 or a redirect away from it, got %d.',
                (string) $user->email,
                $path,
                $status
            )
        );
    }

    /**
     * Flip a module through the model so the cached gate map is flushed the way the
     * application flushes it.
     */
    private function setModuleEnabled(string $slug, bool $enabled): void
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();
        $module->is_enabled = $enabled;
        $module->save();

        $this->assertSame($enabled, Module::enabled($slug));
    }
}
