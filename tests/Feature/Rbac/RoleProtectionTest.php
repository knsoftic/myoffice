<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Enums\PanelType;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-01 §10 "Role protection".
 *
 *   · `is_system` roles can never be renamed or deleted — not even by a Super Admin, who bypasses
 *     every policy, which is why the rule is repeated in the Form Request and in RoleService;
 *   · a role can never be granted a permission that does not exist;
 *   · nobody can grant a permission they do not hold themselves, or mint a role as powerful as
 *     their own.
 */
final class RoleProtectionTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function systemRoleProvider(): array
    {
        return [
            'Super Admin' => [User::SUPER_ADMIN_ROLE],
            'Admin' => ['Admin'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | is_system protection
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('systemRoleProvider')]
    public function a_system_role_cannot_be_renamed(string $name): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->systemRole($name);

        $this->assertTrue($role->isProtected(), $name.' must be an is_system role.');

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => 'Renamed '.$name,
                'label' => 'Renamed',
                'panel' => $role->panelType()->value,
                'level' => (int) $role->level,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame($name, (string) $role->fresh()->name);
    }

    #[Test]
    #[DataProvider('systemRoleProvider')]
    public function a_system_role_cannot_change_panel_or_level(string $name): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->systemRole($name);

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => $role->name,
                'panel' => PanelType::Student->value,
                'level' => 999,
            ])
            ->assertSessionHasErrors(['panel', 'level']);

        $role->refresh();

        $this->assertNotSame(PanelType::Student, $role->panelType());
        $this->assertNotSame(999, (int) $role->level);
    }

    #[Test]
    #[DataProvider('systemRoleProvider')]
    public function a_system_role_cannot_be_deleted(string $name): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->systemRole($name);

        $this->actingAs($actor)
            ->from('/admin/roles')
            ->delete('/admin/roles/'.$role->getKey())
            ->assertRedirect('/admin/roles');

        $this->assertNotNull(
            Role::query()->whereKey($role->getKey())->first(),
            'An is_system role must survive a delete request.'
        );
    }

    #[Test]
    public function the_role_service_refuses_to_delete_a_protected_role(): void
    {
        $role = $this->systemRole(User::SUPER_ADMIN_ROLE);

        $this->expectException(ActionNotAllowedException::class);

        app(RoleService::class)->delete($role);
    }

    /**
     * The positive control: without it, the tests above would also pass if roles were simply
     * never editable.
     */
    #[Test]
    public function a_custom_role_can_be_renamed_and_deleted(): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->createRoleWithPermissions(['users.view_any'], PanelType::Admin, 60);

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => 'Renamed Custom Role',
                'label' => 'Renamed',
                'panel' => PanelType::Admin->value,
                'level' => 60,
                'permissions' => ['users.view_any'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Custom Role', (string) $role->fresh()->name);

        $this->actingAs($actor)
            ->from('/admin/roles')
            ->delete('/admin/roles/'.$role->getKey())
            ->assertRedirect('/admin/roles');

        $this->assertNull(Role::query()->whereKey($role->getKey())->first());
    }

    #[Test]
    public function a_role_that_still_has_members_cannot_be_deleted(): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        User::factory()->create()->assignRole($role);

        $this->actingAs($actor)
            ->from('/admin/roles')
            ->delete('/admin/roles/'.$role->getKey())
            ->assertRedirect('/admin/roles');

        $this->assertNotNull(Role::query()->whereKey($role->getKey())->first());
    }

    /*
    |--------------------------------------------------------------------------
    | Permission grants
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_role_cannot_be_granted_a_permission_that_does_not_exist(): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->createRoleWithPermissions(['users.view_any'], PanelType::Admin, 60);

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => $role->name,
                'panel' => PanelType::Admin->value,
                'level' => 60,
                'permissions' => ['users.view_any', 'ghost_module.view_any'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertSame(
            ['users.view_any'],
            $role->fresh()->permissions->pluck('name')->all(),
            'A rejected submission must leave the matrix exactly as it was.'
        );

        $this->assertDatabaseMissing('permissions', ['name' => 'ghost_module.view_any']);
    }

    /**
     * The Form Request rejects it first; the service repeats the check so no other caller can slip
     * past (RoleService::syncMatrix).
     */
    #[Test]
    public function the_role_service_refuses_an_unknown_permission_name(): void
    {
        $role = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        $this->expectException(ActionNotAllowedException::class);

        app(RoleService::class)->update($role, ['label' => 'x'], ['ghost_module.view_any']);
    }

    #[Test]
    public function a_user_cannot_grant_a_permission_they_do_not_hold(): void
    {
        // The actor may edit roles and may list users — but holds no users.delete.
        $actor = $this->createUserWithPermissions([
            'roles.view_any',
            'roles.view',
            'roles.edit',
            'users.view_any',
        ], PanelType::Admin, 20);

        $this->assertFalse($actor->hasPermissionTo('users.delete'));

        $role = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => $role->name,
                'panel' => PanelType::Admin->value,
                'level' => 60,
                'permissions' => ['users.view_any', 'users.delete'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertSame([], $role->fresh()->permissions->pluck('name')->all());
    }

    #[Test]
    public function a_user_can_grant_a_permission_they_do_hold(): void
    {
        $actor = $this->createUserWithPermissions([
            'roles.view_any',
            'roles.view',
            'roles.edit',
            'users.view_any',
        ], PanelType::Admin, 20);

        $role = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => $role->name,
                'panel' => PanelType::Admin->value,
                'level' => 60,
                'permissions' => ['users.view_any'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['users.view_any'], $role->fresh()->permissions->pluck('name')->all());
    }

    /**
     * Creating a role is not a way to promote yourself: `level` is a rank where lower is stronger.
     */
    #[Test]
    public function a_role_cannot_be_created_at_or_above_the_actors_own_level(): void
    {
        $actor = $this->createUserWithPermissions([
            'roles.view_any',
            'roles.create',
        ], PanelType::Admin, 20);

        $this->actingAs($actor)
            ->from('/admin/roles/create')
            ->post('/admin/roles', [
                'name' => 'Attempted Peer Role',
                'panel' => PanelType::Admin->value,
                'level' => 20,
            ])
            ->assertSessionHasErrors('level');

        $this->assertDatabaseMissing('roles', ['name' => 'Attempted Peer Role']);
    }

    #[Test]
    public function a_role_may_be_created_below_the_actors_own_level(): void
    {
        $actor = $this->createUserWithPermissions([
            'roles.view_any',
            'roles.create',
        ], PanelType::Admin, 20);

        $this->actingAs($actor)
            ->from('/admin/roles/create')
            ->post('/admin/roles', [
                'name' => 'Attempted Junior Role',
                'panel' => PanelType::Admin->value,
                'level' => 45,
            ])
            ->assertSessionHasNoErrors();

        $created = Role::query()->where('name', 'Attempted Junior Role')->first();

        $this->assertNotNull($created);
        $this->assertFalse((bool) $created->is_system, 'Roles created through the UI are never protected.');
    }

    private function systemRole(string $name): Role
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->firstOrFail();
    }
}
