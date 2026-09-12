<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Enums\ModuleGroup;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The seeded catalogue has to be the registry, projected (phase-01 §4, §5, D4: permission names
 * are never typed by hand).
 *
 * Every later phase reads permission names from App\Support\PermissionRegistry, so a drift between
 * the registry and the `permissions` / `modules` tables would silently turn route middleware into
 * a permanent 403. These tests are the guard against that drift.
 */
final class PermissionCatalogueTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function every_registered_permission_exists_in_the_table(): void
    {
        $stored = Permission::query()->pluck('name')->all();
        $missing = array_values(array_diff(PermissionRegistry::permissionNames(), $stored));

        $this->assertSame(
            [],
            $missing,
            'PermissionSeeder has not created every permission the registry declares.'
        );
    }

    #[Test]
    public function the_table_holds_no_permission_the_registry_does_not_declare(): void
    {
        $stored = Permission::query()->pluck('name')->all();
        $extra = array_values(array_diff($stored, PermissionRegistry::permissionNames()));

        $this->assertSame(
            [],
            $extra,
            'The permissions table carries rows the registry no longer declares.'
        );
    }

    #[Test]
    public function every_permission_row_carries_its_matrix_columns(): void
    {
        $expected = [];

        foreach (PermissionRegistry::permissions() as $row) {
            $expected[$row['name']] = $row;
        }

        $rows = Permission::query()->get(['name', 'module', 'ability', 'group', 'label', 'sort_order']);

        foreach ($rows as $permission) {
            $name = (string) $permission->name;

            $this->assertArrayHasKey($name, $expected);

            $this->assertSame($expected[$name]['module'], (string) $permission->module, $name.': wrong module.');
            $this->assertSame(
                $expected[$name]['ability'],
                (string) $permission->getRawOriginal('ability'),
                $name.': wrong ability.'
            );
            $this->assertSame($expected[$name]['group'], (string) $permission->group, $name.': wrong group.');
            $this->assertSame($expected[$name]['label'], (string) $permission->label, $name.': wrong label.');
            $this->assertSame(
                $expected[$name]['sort_order'],
                (int) $permission->sort_order,
                $name.': wrong sort order.'
            );
        }
    }

    #[Test]
    public function every_registered_module_exists_as_a_row_with_the_registry_values(): void
    {
        foreach (PermissionRegistry::modules() as $slug => $definition) {
            $module = Module::query()->where('slug', $slug)->first();

            $this->assertNotNull($module, sprintf('Module %s is not seeded.', $slug));
            $this->assertSame($definition['name'], (string) $module->name, $slug.': wrong name.');
            $this->assertSame($definition['is_core'], (bool) $module->is_core, $slug.': wrong is_core.');
            $this->assertSame($definition['sort'], (int) $module->sort_order, $slug.': wrong sort order.');

            $this->assertInstanceOf(ModuleGroup::class, $module->group);
            $this->assertSame($definition['group'], $module->group, $slug.': wrong group.');
        }
    }

    #[Test]
    public function the_system_modules_and_portal_namespaces_are_core_and_the_business_modules_are_not(): void
    {
        // phase-01 §4: the System group is the core set. The four `*_portal` entries are core too:
        // they are permission prefixes for the four non-admin panels, so disabling one would deny
        // `student_portal.*` (etc.) to everyone, Super Admin included, and lock a whole panel's
        // users out of their own dashboard.
        $coreSlugs = PermissionRegistry::coreSlugs();

        $this->assertSame(
            [
                'dashboard',
                'users',
                'roles',
                'permissions',
                'modules',
                'settings',
                'activity_log',
                'login_history',
                'backups',
                'global_search',
                'collaborator_portal',
                'student_portal',
                'teacher_portal',
                'client_portal',
            ],
            $coreSlugs,
            'The core module set is fixed by the contract plus the four portal namespaces.'
        );

        $this->assertSame(
            [],
            Module::query()->where('is_core', true)->pluck('slug')->diff($coreSlugs)->values()->all(),
            'No module outside the contract list may be marked core.'
        );
    }

    #[Test]
    public function the_super_admin_role_is_granted_every_permission(): void
    {
        $role = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $held = $role->permissions->pluck('name')->all();
        $missing = array_values(array_diff(PermissionRegistry::permissionNames(), $held));

        // Belt and braces next to the Gate::before short-circuit (phase-01 §5).
        $this->assertSame([], $missing, 'The Super Admin role must hold every declared permission.');
    }

    #[Test]
    public function the_contract_roles_are_all_seeded_on_the_right_panel(): void
    {
        $expected = [
            'Super Admin' => ['admin', 1, true],
            'Admin' => ['admin', 5, true],
            'HR' => ['admin', 20, false],
            'Accountant' => ['admin', 20, false],
            'Project Manager' => ['admin', 20, false],
            'Developer' => ['admin', 40, false],
            'Designer' => ['admin', 40, false],
            'SEO Expert' => ['admin', 40, false],
            'Digital Marketer' => ['admin', 40, false],
            'Sales Executive' => ['admin', 30, false],
            'Receptionist' => ['admin', 35, false],
            'Support Agent' => ['admin', 35, false],
            'Institute Manager' => ['admin', 15, false],
            'Course Coordinator' => ['admin', 25, false],
            'Teacher' => ['teacher', 50, false],
            'Student' => ['student', 60, false],
            'Client' => ['client', 60, false],
            'Collaborator' => ['collaborator', 60, false],
        ];

        foreach ($expected as $name => [$panel, $level, $isSystem]) {
            $role = Role::query()->where('name', $name)->first();

            $this->assertNotNull($role, sprintf('The %s role is not seeded.', $name));
            $this->assertSame($panel, $role->panelType()->value, $name.': wrong panel.');
            $this->assertSame($level, (int) $role->level, $name.': wrong level.');
            $this->assertSame($isSystem, (bool) $role->is_system, $name.': wrong is_system flag.');
        }

        $this->assertSame(count($expected), Role::query()->whereIn('name', array_keys($expected))->count());
    }

    /**
     * Each non-admin panel role may only hold its own portal namespace — that is what keeps a
     * student out of the admin modules even before the panel middleware runs.
     */
    #[Test]
    public function a_portal_role_holds_only_its_own_portal_permissions(): void
    {
        $portals = [
            'Student' => 'student_portal.',
            'Teacher' => 'teacher_portal.',
            'Client' => 'client_portal.',
            'Collaborator' => 'collaborator_portal.',
        ];

        foreach ($portals as $roleName => $prefix) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $held = $role->permissions->pluck('name')->all();

            $this->assertNotEmpty($held, $roleName.' must hold its portal permissions.');

            foreach ($held as $permission) {
                $this->assertStringStartsWith(
                    $prefix,
                    (string) $permission,
                    sprintf('The %s role must not hold %s.', $roleName, $permission)
                );
            }
        }
    }

    /**
     * phase-01 §5: requesting a payout is an opt-in the administrator grants, so the seeded
     * Collaborator role holds every portal ability except that one.
     */
    #[Test]
    public function the_collaborator_role_is_seeded_without_the_payout_request_permission(): void
    {
        $role = Role::query()->where('name', 'Collaborator')->firstOrFail();
        $held = $role->permissions->pluck('name')->all();

        $this->assertNotContains('collaborator_portal.payout_request', $held);
        $this->assertContains('collaborator_portal.dashboard', $held);
        $this->assertContains('collaborator_portal.statement_download', $held);
    }

    /**
     * phase-01 §5: the Admin role is everything except module toggles, backups and role deletion.
     */
    #[Test]
    public function the_admin_role_is_seeded_without_module_toggles_backups_or_role_deletion(): void
    {
        $role = Role::query()->where('name', 'Admin')->firstOrFail();
        $held = $role->permissions->pluck('name')->all();

        foreach ($held as $permission) {
            $this->assertStringStartsNotWith('modules.', (string) $permission);
            $this->assertStringStartsNotWith('backups.', (string) $permission);
        }

        $this->assertNotContains('roles.delete', $held);
        $this->assertContains('users.delete', $held);
        $this->assertContains('dashboard.view_any', $held);
    }
}
