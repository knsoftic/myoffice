<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Enums\UserStatus;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-01 §10 "RBAC": a permission-less user gets 403 on each admin route; granting the exact
 * permission makes it 200.
 *
 * The fixture is deliberately minimal: a role that carries the admin panel (so `panel:admin` lets
 * the user in) and *nothing else*, so the single permission under test is the only difference
 * between 403 and 200. Nothing here is hardcoded beyond the route → permission pairs the route
 * file declares.
 */
final class PermissionEnforcementTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Every Phase-1 admin GET route and the one permission its middleware demands
     * (routes/admin.php).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminRouteProvider(): array
    {
        return [
            'dashboard' => ['/admin', 'dashboard.view_any'],
            'users index' => ['/admin/users', 'users.view_any'],
            'user create' => ['/admin/users/create', 'users.create'],
            'roles index' => ['/admin/roles', 'roles.view_any'],
            'role create' => ['/admin/roles/create', 'roles.create'],
            'permissions index' => ['/admin/permissions', 'permissions.view_any'],
            'modules index' => ['/admin/modules', 'modules.view_any'],
            'activity log index' => ['/admin/activity-log', 'activity_log.view_logs'],
            'activity log export' => ['/admin/activity-log/export', 'activity_log.export'],
            'login history index' => ['/admin/login-history', 'login_history.view_logs'],
            'login history export' => ['/admin/login-history/export', 'login_history.export'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    #[DataProvider('adminRouteProvider')]
    public function a_permission_less_user_is_refused(string $path, string $permission): void
    {
        $user = $this->createUserWithPermissions([]);

        $this->assertTrue(
            $user->canAccessPanel('admin'),
            'The fixture must be able to enter the panel, so the 403 can only come from the permission check.'
        );

        $this->actingAs($user)
            ->get($path)
            ->assertForbidden();
    }

    #[Test]
    #[DataProvider('adminRouteProvider')]
    public function granting_the_exact_permission_opens_the_route(string $path, string $permission): void
    {
        $user = $this->createUserWithPermissions([$permission]);

        $response = $this->actingAs($user)->get($path);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            sprintf('Granting %s must open %s; got %d.', $permission, $path, $response->getStatusCode())
        );
    }

    /**
     * Holding a neighbouring ability on the same module is not enough: the route demands the exact
     * permission, which is what stops "can list users" from becoming "can create users".
     */
    #[Test]
    public function a_neighbouring_ability_on_the_same_module_does_not_open_the_route(): void
    {
        $user = $this->createUserWithPermissions(['users.view_any']);

        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
        $this->actingAs($user)->get('/admin/roles')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Write routes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_a_users_status_needs_the_change_status_permission(): void
    {
        $target = User::factory()->create();

        $withoutPermission = $this->createUserWithPermissions(['users.view_any', 'users.edit']);

        $this->actingAs($withoutPermission)
            ->from('/admin/users')
            ->patch('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Testing',
            ])
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $target->fresh()->status);

        $withPermission = $this->createUserWithPermissions(['users.view_any', 'users.change_status']);

        $this->actingAs($withPermission)
            ->from('/admin/users')
            ->patch('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Testing',
            ])
            ->assertRedirect('/admin/users');

        $this->assertSame(UserStatus::Suspended, $target->fresh()->status);
    }

    #[Test]
    public function deleting_a_user_needs_the_delete_permission(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->createUserWithPermissions(['users.view_any', 'users.edit']))
            ->from('/admin/users')
            ->delete('/admin/users/'.$target->getKey())
            ->assertForbidden();

        $this->assertNotSoftDeleted($target);

        $this->actingAs($this->createUserWithPermissions(['users.view_any', 'users.delete']))
            ->from('/admin/users')
            ->delete('/admin/users/'.$target->getKey())
            ->assertRedirect('/admin/users');

        $this->assertSoftDeleted($target);
    }

    #[Test]
    public function toggling_a_module_needs_the_change_status_permission(): void
    {
        $module = Module::query()->where('slug', 'projects')->firstOrFail();

        $this->actingAs($this->createUserWithPermissions(['modules.view_any']))
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $module->fresh()->is_enabled);

        $this->actingAs($this->createUserWithPermissions(['modules.view_any', 'modules.change_status']))
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false])
            ->assertRedirect('/admin/modules');

        $this->assertFalse((bool) $module->fresh()->is_enabled);
    }

    /*
    |--------------------------------------------------------------------------
    | Account area
    |--------------------------------------------------------------------------
    */

    /**
     * The /account screens are shared by all five panels and are gated by `auth` alone — a user
     * with no module permission at all must still be able to manage their own account.
     */
    #[Test]
    public function the_account_area_needs_no_module_permission(): void
    {
        $user = $this->createUserWithPermissions([]);

        foreach (['/account/profile', '/account/password', '/account/sessions', '/account/login-history'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }
}
