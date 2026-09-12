<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Nobody grants themselves power, and a role grant looks at the account receiving it.
 *
 * The hole this locks shut: `UserPolicy::update()` deliberately allows editing your **own** row
 * (name, phone, avatar), and the edit form carries the role checkboxes. The only check on a grant
 * was `RolePolicy::assign()`, which compares the *role's* level to the actor's — it never looked at
 * the target — so every role numerically weaker than your own was self-service. An Admin (level 5,
 * deliberately denied `modules.*`, `backups.*` and `roles.delete` by RoleSeeder) could tick any
 * level-6-or-weaker role that happened to carry one of those abilities and walk away with it.
 *
 * `UserPolicy::assignRoles()` — which requires `users.assign`, that the actor outranks the target,
 * and `RolePolicy::assign()` for the role itself — already stated the correct rule and was never
 * called by anything. It is now the single enforcement point: the Form Request asks for it, the
 * controller filters the checkboxes with it, and `UserService` repeats the self-check outside the
 * Gate because `Gate::before` hands a Super Admin every ability.
 */
final class SelfRoleEscalationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | The exploit
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_cannot_grant_themselves_a_role_they_are_otherwise_allowed_to_hand_out(): void
    {
        $actor = $this->roleManager();
        $ownRole = $actor->roles->first();

        // Weaker than the actor by level, so the old role-only check waved it through — and it
        // carries abilities the actor does not hold, which is the whole point of the escalation.
        $prize = $this->createRoleWithPermissions(['modules.view_any', 'modules.change_status'], 'admin', 30);

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => (string) $actor->name,
                'email' => (string) $actor->email,
                'roles' => [$ownRole->getKey(), $prize->getKey()],
            ])
            ->assertSessionHasErrors('roles');

        $this->forgetPermissionCache();
        $actor = $actor->fresh()->load('roles');

        $this->assertSame(
            [$ownRole->getKey()],
            $actor->roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'The role set of your own account must be untouched.'
        );

        $this->assertFalse(
            $actor->can('modules.change_status'),
            'A self-grant would have handed the actor an ability their own role is denied.'
        );
    }

    /**
     * `Gate::before` gives a Super Admin every ability before any policy runs, so the self-check
     * cannot live in the Gate alone.
     */
    #[Test]
    public function a_super_admin_cannot_grant_themselves_a_role_either(): void
    {
        $actor = $this->createSuperAdmin();
        $superRole = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $extra = $this->createRoleWithPermissions([], 'admin', 30);

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => (string) $actor->name,
                'email' => (string) $actor->email,
                'roles' => [$superRole->getKey(), $extra->getKey()],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertSame(
            [$superRole->getKey()],
            $actor->fresh()->load('roles')->roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
    }

    #[Test]
    public function a_user_cannot_drop_a_role_from_their_own_account(): void
    {
        $actor = $this->roleManager();
        $ownRole = $actor->roles->first();

        $second = $this->createRoleWithPermissions([], 'admin', 30);
        $actor->assignRole($second);
        $this->forgetPermissionCache();
        $actor = $actor->fresh()->load('roles');

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => (string) $actor->name,
                'email' => (string) $actor->email,
                'roles' => [$ownRole->getKey()],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertCount(
            2,
            $actor->fresh()->load('roles')->roles,
            'Removing your own role is still changing your own role set.'
        );
    }

    /**
     * Editing your own profile is still allowed — the guard is about the role set, not about the
     * screen.
     */
    #[Test]
    public function you_can_still_edit_your_own_profile_without_touching_your_roles(): void
    {
        $actor = $this->roleManager();

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => 'My New Name',
                'email' => (string) $actor->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/users/'.$actor->getKey());

        $this->assertSame('My New Name', $actor->fresh()->name);
        $this->assertCount(1, $actor->fresh()->load('roles')->roles);
    }

    /**
     * Defence in depth: the same rule with no HTTP layer and no Form Request.
     */
    #[Test]
    public function the_user_service_refuses_a_self_role_change(): void
    {
        $actor = $this->createSuperAdmin();
        $extra = $this->createRoleWithPermissions([], 'admin', 30);

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->update(
            $actor,
            ['name' => (string) $actor->name, 'roles' => [$extra->getKey()]],
            $actor,
        );
    }

    #[Test]
    public function the_user_service_accepts_a_self_save_that_repeats_the_same_role_set(): void
    {
        $actor = $this->roleManager();
        $ownRole = $actor->roles->first();

        app(UserService::class)->update(
            $actor,
            ['name' => 'Unchanged Roles', 'roles' => [$ownRole->getKey()]],
            $actor,
        );

        $this->assertSame('Unchanged Roles', $actor->fresh()->name);
        $this->assertCount(1, $actor->fresh()->load('roles')->roles);
    }

    /*
    |--------------------------------------------------------------------------
    | The policy that does the deciding
    |--------------------------------------------------------------------------
    */

    /**
     * The difference between the two questions, stated directly: `assign` only knows about the role,
     * `assignRoles` also knows who is receiving it. Asking the wrong one was the bug.
     */
    #[Test]
    public function assign_roles_considers_the_target_while_assign_only_considers_the_role(): void
    {
        $actor = $this->roleManager();
        $grantable = $this->createRoleWithPermissions([], 'admin', 40);

        $weaker = $this->createUserWithPermissions([], 'admin', 50);
        $peer = $this->createUserWithPermissions([], 'admin', 20);
        $stronger = $this->createUserWithPermissions([], 'admin', 5);

        // The role's own level passes for everyone — this is all the old check asked.
        $this->assertTrue(Gate::forUser($actor)->allows('assign', $grantable));

        $this->assertTrue(
            Gate::forUser($actor)->allows('assignRoles', [$weaker, $grantable]),
            'An account the actor outranks may receive a role the actor may hand out.'
        );

        $this->assertFalse(
            Gate::forUser($actor)->allows('assignRoles', [$actor, $grantable]),
            'You never outrank yourself, so a self-grant is refused.'
        );

        $this->assertFalse(
            Gate::forUser($actor)->allows('assignRoles', [$peer, $grantable]),
            'Peers do not manage each other.'
        );

        $this->assertFalse(
            Gate::forUser($actor)->allows('assignRoles', [$stronger, $grantable]),
            'A stronger account is never managed from below.'
        );

        // A brand-new account has no rank of its own, so the create screen still works.
        $this->assertTrue(Gate::forUser($actor)->allows('assignRoles', [new User, $grantable]));
    }

    /**
     * `users.assign` is now genuinely required, which is what proves the dead policy method is the
     * one being consulted: `roles.assign` on its own is no longer enough.
     */
    #[Test]
    public function a_role_grant_needs_users_assign_and_not_only_roles_assign(): void
    {
        $actor = $this->createUserWithPermissions(
            ['users.view_any', 'users.view', 'users.edit', 'roles.assign'],
            'admin',
            20,
        );

        $target = $this->createUserWithPermissions([], 'admin', 50);
        $grantable = $this->createRoleWithPermissions([], 'admin', 40);

        $this->assertTrue(Gate::forUser($actor)->allows('assign', $grantable));
        $this->assertFalse(Gate::forUser($actor)->allows('assignRoles', [$target, $grantable]));

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), [
                'name' => (string) $target->name,
                'email' => (string) $target->email,
                'roles' => [$grantable->getKey()],
            ])
            ->assertSessionHasErrors('roles');
    }

    #[Test]
    public function a_role_stronger_than_the_actor_is_still_refused_for_anyone(): void
    {
        $actor = $this->roleManager();
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $superRole = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), [
                'name' => (string) $target->name,
                'email' => (string) $target->email,
                'roles' => [$superRole->getKey()],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertFalse($target->fresh()->hasRole(User::SUPER_ADMIN_ROLE));
    }

    /**
     * A grant the actor *is* allowed to make still goes through, so none of the above is a blanket
     * refusal.
     */
    #[Test]
    public function a_permitted_grant_to_a_weaker_account_still_works(): void
    {
        $actor = $this->roleManager();
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $grantable = $this->createRoleWithPermissions([], 'admin', 40);

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), [
                'name' => (string) $target->name,
                'email' => (string) $target->email,
                'roles' => [$grantable->getKey()],
            ])
            ->assertSessionHasNoErrors();

        $this->forgetPermissionCache();

        $this->assertTrue($target->fresh()->hasRole($grantable->name));
    }

    /*
    |--------------------------------------------------------------------------
    | The form says the same thing the server does
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function your_own_edit_screen_offers_no_role_checkboxes(): void
    {
        $actor = $this->roleManager();

        $response = $this->actingAs($actor)->get('/admin/users/'.$actor->getKey().'/edit');

        $response->assertOk();
        $response->assertSee('You cannot change your own roles', false);
        $response->assertDontSee('name="roles[]"', false);
    }

    /**
     * The checkbox list is filtered with the same `assignRoles` question the server asks, so it must
     * carry exactly the grants that would succeed: the role is identified by the `value` the form
     * posts back, not by its on-screen text (the card renders `Role::displayName()`, which is the
     * label).
     */
    #[Test]
    public function someone_elses_edit_screen_still_offers_the_roles_you_may_grant(): void
    {
        $actor = $this->roleManager();
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $grantable = $this->createRoleWithPermissions([], 'admin', 40, 'Grantable Test Role');
        $superRole = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $response = $this->actingAs($actor)->get('/admin/users/'.$target->getKey().'/edit');

        $response->assertOk();
        $response->assertSee('name="roles[]"', false);
        $response->assertSee($grantable->displayName());

        // And only those: a role stronger than the actor is never offered, because the same Gate
        // question that filters the list is the one that would refuse the submit. Asserted on the
        // list the view is handed, not on a `value="1"` substring that half a dozen other inputs
        // could satisfy.
        $response->assertViewHas('roles', function (Collection $roles) use ($grantable, $superRole): bool {
            $ids = $roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            return in_array($grantable->getKey(), $ids, true)
                && ! in_array($superRole->getKey(), $ids, true);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | roles.view is not a staff directory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function roles_view_alone_does_not_reveal_who_holds_a_role(): void
    {
        $actor = $this->createUserWithPermissions(['roles.view_any', 'roles.view'], 'admin', 20);

        $superRole = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $member = $this->createSuperAdmin(['email' => 'hidden.super@example.test']);

        $response = $this->actingAs($actor)->get('/admin/roles/'.$superRole->getKey());

        $response->assertOk();
        $response->assertDontSee('hidden.super@example.test');
        $response->assertDontSee((string) $member->name);
        $response->assertSee('Members hidden');
    }

    /**
     * Holding `users.view` is not enough either, because the rank rule still applies — exactly as it
     * does on /admin/users/{id}.
     */
    #[Test]
    public function members_the_actor_may_not_open_on_the_users_screen_stay_hidden_here_too(): void
    {
        $actor = $this->createUserWithPermissions(
            ['roles.view_any', 'roles.view', 'users.view_any', 'users.view'],
            'admin',
            20,
        );

        $superRole = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $member = $this->createSuperAdmin(['email' => 'still.hidden@example.test']);

        // The same account is refused on the users screen, which is the rule being mirrored.
        $this->actingAs($actor)->get('/admin/users/'.$member->getKey())->assertForbidden();

        $this->actingAs($actor)
            ->get('/admin/roles/'.$superRole->getKey())
            ->assertOk()
            ->assertDontSee('still.hidden@example.test');
    }

    #[Test]
    public function a_super_admin_still_sees_the_membership_list(): void
    {
        $actor = $this->createSuperAdmin();
        $superRole = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $member = $this->createSuperAdmin(['email' => 'visible.super@example.test']);

        $this->actingAs($actor)
            ->get('/admin/roles/'.$superRole->getKey())
            ->assertOk()
            ->assertSee('visible.super@example.test');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * An administrator who may edit accounts and hand out roles — everything the grant needs except
     * the right to do it to themselves.
     */
    private function roleManager(): User
    {
        return $this->createUserWithPermissions(
            ['users.view_any', 'users.view', 'users.edit', 'users.assign', 'roles.assign'],
            'admin',
            20,
        );
    }
}
