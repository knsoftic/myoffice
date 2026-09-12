<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Enums\PanelType;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-01 §10 "Super Admin": bypasses every permission check.
 *
 * The bypass is `Gate::before` rule 2 in AppServiceProvider, keyed on the role **name**, not on
 * the grants the role happens to hold. To prove it is really the short-circuit doing the work —
 * and not RoleSeeder having handed the role every permission — the tests below strip the Super
 * Admin role's permission matrix first and then assert everything still opens.
 *
 * The two places the bypass must *not* reach are asserted too: a disabled module (rule 1 runs
 * first) and another panel (panel access is about roles, not power).
 */
final class SuperAdminBypassTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function a_super_admin_with_no_granted_permissions_still_passes_every_check(): void
    {
        $user = $this->stripAndRebuildSuperAdmin();

        $this->assertSame(
            0,
            $user->getAllPermissions()->count(),
            'The fixture must hold no granted permission, so only Gate::before can allow anything.'
        );

        foreach (PermissionRegistry::permissionNames() as $permission) {
            $this->assertTrue(
                Gate::forUser($user)->allows($permission),
                sprintf('Super Admin must be allowed %s by the Gate::before short-circuit.', $permission)
            );
        }
    }

    #[Test]
    public function a_super_admin_with_no_granted_permissions_still_opens_every_admin_screen(): void
    {
        $user = $this->stripAndRebuildSuperAdmin();

        $screens = [
            '/admin',
            '/admin/users',
            '/admin/users/create',
            '/admin/roles',
            '/admin/roles/create',
            '/admin/permissions',
            '/admin/modules',
            '/admin/activity-log',
            '/admin/login-history',
        ];

        foreach ($screens as $path) {
            $response = $this->actingAs($user)->get($path);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf('Expected %s to open for a Super Admin, got %d.', $path, $response->getStatusCode())
            );
        }
    }

    #[Test]
    public function the_bypass_also_covers_policy_decisions(): void
    {
        $user = $this->stripAndRebuildSuperAdmin();
        $target = User::factory()->create();
        $customRole = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        // UserPolicy / RolePolicy are never consulted: Gate::before answers first.
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $target));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $target));
        $this->assertTrue(Gate::forUser($user)->allows('update', $customRole));
    }

    /**
     * Rule 1 of `Gate::before` runs before the Super Admin bypass, which is what makes "disabled
     * means closed for everyone" true (D5).
     */
    #[Test]
    public function the_bypass_does_not_survive_a_disabled_module(): void
    {
        $user = $this->createSuperAdmin();

        $this->assertTrue(Gate::forUser($user)->allows('projects.view_any'));

        $this->switchModule('projects', false);

        $this->assertTrue(
            Gate::forUser($user)->denies('projects.view_any'),
            'A disabled module must deny its abilities before the Super Admin short-circuit runs.'
        );
    }

    /**
     * Panel access is decided by the panels the user's roles belong to, never by rank — a Super
     * Admin is not a student.
     */
    #[Test]
    public function the_bypass_does_not_open_another_panel(): void
    {
        $user = $this->createSuperAdmin();

        $this->assertTrue($user->canAccessPanel(PanelType::Admin));

        foreach (['/student', '/teacher', '/client', '/collaborator'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    /**
     * A Super Admin account whose role grants were emptied — the only thing left is the role name
     * the Gate short-circuits on.
     */
    private function stripAndRebuildSuperAdmin(): User
    {
        $role = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $role->syncPermissions([]);

        $this->forgetPermissionCache();

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->forgetPermissionCache();

        return $user->fresh()->load('roles');
    }
}
