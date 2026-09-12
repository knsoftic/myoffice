<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The two invariants that have to hold even for a Super Admin (UserService): nobody deletes their
 * own account, and the system always keeps at least one Super Admin.
 *
 * They live in the service rather than only in UserPolicy because `Gate::before` hands the Super
 * Admin role an unconditional pass, so a policy alone cannot protect an administrator from
 * themselves.
 */
final class UserSafeguardTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function a_super_admin_cannot_delete_their_own_account(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey())
            ->delete('/admin/users/'.$actor->getKey())
            ->assertRedirect('/admin/users/'.$actor->getKey());

        $this->assertNotSoftDeleted($actor);
    }

    #[Test]
    public function an_ordinary_administrator_cannot_delete_their_own_account(): void
    {
        $actor = $this->createUserWithPermissions(['users.view_any', 'users.delete'], 'admin', 20);

        $this->actingAs($actor)
            ->from('/admin/users')
            ->delete('/admin/users/'.$actor->getKey())
            ->assertForbidden();

        $this->assertNotSoftDeleted($actor);
    }

    #[Test]
    public function the_user_service_refuses_self_deletion(): void
    {
        $actor = $this->createSuperAdmin();

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->delete($actor, $actor);
    }

    #[Test]
    public function a_super_admin_cannot_suspend_or_reset_their_own_account(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey())
            ->patch('/admin/users/'.$actor->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Oops',
            ]);

        $this->assertSame(
            UserStatus::Active,
            $actor->fresh()->status,
            'Suspending yourself would lock the only administrator out of their own system.'
        );

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey())
            ->post('/admin/users/'.$actor->getKey().'/reset-password', []);

        $this->assertFalse((bool) $actor->fresh()->must_change_password);
    }

    /*
    |--------------------------------------------------------------------------
    | The last Super Admin
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_last_super_admin_cannot_be_deleted(): void
    {
        $only = $this->soleSuperAdmin();
        $actor = User::factory()->create();

        $this->assertTrue(app(UserService::class)->isLastSuperAdmin($only));

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->delete($only, $actor);
    }

    #[Test]
    public function a_super_admin_can_be_deleted_while_another_one_remains(): void
    {
        $actor = $this->createSuperAdmin();
        $other = $this->createSuperAdmin();

        $this->assertFalse(app(UserService::class)->isLastSuperAdmin($other));

        $this->actingAs($actor)
            ->from('/admin/users')
            ->delete('/admin/users/'.$other->getKey())
            ->assertRedirect('/admin/users');

        $this->assertSoftDeleted($other);
    }

    /**
     * Deleting a Super Admin is a soft delete, so the audit history survives — and the account
     * stops counting towards "is there still a Super Admin?".
     */
    #[Test]
    public function deleting_a_super_admin_keeps_the_row_for_the_audit_trail(): void
    {
        $actor = $this->createSuperAdmin();
        $other = $this->createSuperAdmin();

        $this->actingAs($actor)->from('/admin/users')->delete('/admin/users/'.$other->getKey());

        $this->assertNotNull(
            User::withTrashed()->whereKey($other->getKey())->first(),
            'User rows are soft-deleted and kept for audit history.'
        );
    }

    /**
     * Reduce the fixture to exactly one Super Admin by detaching the role from every other holder
     * (the seeder creates one; other tests may create more).
     */
    private function soleSuperAdmin(): User
    {
        $role = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $holders = User::query()
            ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
            ->get();

        foreach ($holders as $index => $holder) {
            if ($index === 0) {
                continue;
            }

            $holder->removeRole($role);
        }

        $this->forgetPermissionCache();

        $survivor = $holders->first();

        $this->assertInstanceOf(User::class, $survivor, 'The seeded fixture must contain a Super Admin.');

        return $survivor->fresh();
    }
}
