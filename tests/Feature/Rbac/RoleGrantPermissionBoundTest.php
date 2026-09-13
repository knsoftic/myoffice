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
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The Phase 1 carryover items that bound what the **user forms** may hand out
 * (DEVELOPMENT_LOG §8 T11, T12, T13).
 *
 * **T11 — a role grant is bounded by the actor's own permissions, not only by `roles.level`.**
 * The role *editor* has always refused to grant a permission the actor does not hold
 * (`ValidatesPermissionGrants`). A role *grant* only compared numbers: `roles.level` is typed into
 * a form and says nothing about what the role can do. So the escalation below was open —
 * latent only because no seeded role happens to hold a permission the Admin lacks:
 *
 *   1. the Admin role (level 5) is deliberately denied every `modules.*` permission by RoleSeeder;
 *   2. an Admin creates a custom role at level 30 — weaker than their own, so `RolePolicy::assign()`
 *      is satisfied — and puts `modules.change_status` in it (the role editor allows this only for
 *      permissions the Admin holds… but creating the role is not the attack, granting it is);
 *   3. the Admin creates a puppet account, grants it that role, signs in as it, and now holds an
 *      ability the system deliberately withheld from them.
 *
 * Step 2 is already blocked for an Admin by the role editor, which is why the test builds the role
 * as a Super Admin — the honest version of the threat, where the strong role exists legitimately
 * (a contractor role, a migration, a future seeder) and the Admin merely tries to hand it out.
 * `UserPolicy::assignRoles()` now refuses that, and it is refused again in `UserService` for callers
 * that never touch a Form Request.
 *
 * **T12** — `roles` used to be `required` on every save of somebody else's account, while an actor
 * without `users.assign` is offered no role checkboxes at all: their every save answered "Choose at
 * least one role" with nothing on the page able to satisfy it.
 *
 * **T13** — the admin forms validated passwords with `Password::defaults()` (8 characters, no
 * complexity) while `/account/password` enforces `PasswordPolicy` (10, mixed case, number, symbol,
 * not breached). An administrator must not be able to set a weaker password than the owner could.
 */
final class RoleGrantPermissionBoundTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Satisfies App\Services\Auth\PasswordPolicy. */
    private const STRONG_PASSWORD = 'Tq7%vLmz!2Rk9d';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        // PasswordPolicy calls the haveibeenpwned range API; the suite must not depend on a
        // network. Every other rule in the policy still runs.
        $this->withoutCompromisedPasswordCheck();
    }

    /*
    |--------------------------------------------------------------------------
    | T11 — the permission bound
    |--------------------------------------------------------------------------
    */

    /**
     * The escalation itself: the real seeded Admin, a real level-30 role, a real puppet account.
     */
    #[Test]
    public function an_admin_cannot_grant_a_role_carrying_a_permission_the_admin_does_not_hold(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $puppet = $this->createUserWithPermissions([], 'admin', 50);
        $prize = $this->escalationRole();

        // The premise, asserted rather than assumed: if a later seeder ever grants the Admin role
        // `modules.*`, this test must fail loudly instead of passing for the wrong reason.
        $this->assertFalse(
            $admin->hasPermissionTo('modules.change_status'),
            'RoleSeeder withholds every modules.* permission from the Admin role — the premise of T11.'
        );

        $this->assertTrue($admin->hasPermissionTo('users.assign'));
        $this->assertTrue($admin->hasPermissionTo('roles.assign'));

        $this->actingAs($admin)
            ->from('/admin/users/'.$puppet->getKey().'/edit')
            ->put('/admin/users/'.$puppet->getKey(), $this->payload($puppet, [$prize->getKey()]))
            ->assertSessionHasErrors('roles');

        $this->forgetPermissionCache();

        $this->assertFalse(
            $puppet->fresh()->hasRole($prize->name),
            'A granted role the actor could not hand out would be a permission escalation through a puppet account.'
        );

        $this->assertFalse(
            $puppet->fresh()->can('modules.change_status'),
            'The whole point of the bound: the ability never reaches the puppet.'
        );
    }

    /**
     * The level rule on its own still says yes — which is exactly why the permission bound had to
     * be added rather than assumed.
     */
    #[Test]
    public function the_role_level_rule_passes_while_the_permission_bound_refuses(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $prize = $this->escalationRole();

        $this->assertTrue(
            Gate::forUser($admin)->allows('assign', $prize),
            'RolePolicy::assign() compares levels, and level 30 is weaker than the Admin’s level 5.'
        );

        $this->assertTrue(
            Gate::forUser($admin)->allows('view', $target),
            'The Admin outranks the target, so nothing about the *target* is the reason.'
        );

        $this->assertFalse(
            Gate::forUser($admin)->allows('assignRoles', [$target, $prize]),
            'The grant must be refused on the permissions the role carries.'
        );
    }

    #[Test]
    public function a_super_admin_can_grant_the_same_role(): void
    {
        $actor = $this->createSuperAdmin();
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $prize = $this->escalationRole();

        $this->assertTrue(Gate::forUser($actor)->allows('assignRoles', [$target, $prize]));

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [$prize->getKey()]))
            ->assertSessionHasNoErrors();

        $this->forgetPermissionCache();

        $this->assertTrue(
            $target->fresh()->hasRole($prize->name),
            'A Super Admin holds every permission in the table, so nothing is out of their reach to grant.'
        );
    }

    /**
     * Not a blanket refusal: a role whose permissions the actor *does* hold is still grantable.
     */
    #[Test]
    public function a_role_within_the_actors_own_permissions_is_still_grantable(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $allowed = $this->createRoleWithPermissions(['users.view_any', 'users.view'], 'admin', 30);

        $this->actingAs($admin)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [$allowed->getKey()]))
            ->assertSessionHasNoErrors();

        $this->forgetPermissionCache();

        $this->assertTrue($target->fresh()->hasRole($allowed->name));
    }

    /**
     * The two layers draw the line in deliberately different places, so both are pinned down here.
     *
     * `UserService` bounds a **grant**: removing a role is not granting one, so an administrator who
     * may not hand a role out can still strip it, which is the safe direction (privilege goes down).
     *
     * The Form Request is stricter and was already: `UpdateUserRequest` checks every role in the
     * *delta*, added or removed, against `assignRoles`. Through the edit form an Admin therefore
     * cannot remove "Module Operator" either — they must not silently rewrite a role set they are
     * not allowed to compose. That is Phase 1 behaviour and is left exactly as it was; nothing here
     * loosens it.
     */
    #[Test]
    public function revoking_a_role_beyond_reach_is_refused_by_the_form_and_allowed_by_the_service(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $prize = $this->escalationRole();

        $target = $this->createUserWithPermissions([], 'admin', 50);
        $keep = (int) $target->roles->first()->getKey();
        $target->assignRole($prize);
        $this->forgetPermissionCache();
        $target = $target->fresh()->load('roles');

        $this->actingAs($admin)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [$keep]))
            ->assertSessionHasErrors('roles');

        $this->forgetPermissionCache();

        $this->assertTrue(
            $target->fresh()->hasRole($prize->name),
            'The form refuses any role delta the actor could not have composed, in either direction.'
        );

        // The service's own bound is about granting, so the same removal goes through there.
        app(UserService::class)->update(
            $target->fresh()->load('roles'),
            ['name' => (string) $target->name, 'roles' => [$keep]],
            $admin,
        );

        $this->forgetPermissionCache();

        $this->assertFalse(
            $target->fresh()->hasRole($prize->name),
            'Stripping a too-strong role reduces privilege, so the grant bound must not block it.'
        );
    }

    /**
     * The form offers exactly what the server would accept — so the checkbox for an un-grantable
     * role is not on the page at all.
     */
    #[Test]
    public function the_edit_and_create_screens_never_offer_a_role_beyond_the_actors_reach(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $prize = $this->escalationRole();

        $excludesPrize = fn (Collection $roles): bool => ! in_array(
            $prize->getKey(),
            $roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            true,
        );

        $this->actingAs($admin)
            ->get('/admin/users/'.$target->getKey().'/edit')
            ->assertOk()
            ->assertViewHas('roles', $excludesPrize);

        $this->actingAs($admin)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertViewHas('roles', $excludesPrize);
    }

    /*
    |--------------------------------------------------------------------------
    | T11 — defence in depth: the same rule with no HTTP layer
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_user_service_refuses_a_grant_beyond_the_actors_reach(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $target = $this->createUserWithPermissions([], 'admin', 50);
        $prize = $this->escalationRole();

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->update(
            $target,
            ['name' => (string) $target->name, 'roles' => [$prize->getKey()]],
            $admin,
        );
    }

    #[Test]
    public function the_user_service_refuses_the_same_grant_on_a_brand_new_account(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $prize = $this->escalationRole();

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->create(
            [
                'name' => 'Puppet Account',
                'email' => 'puppet@example.test',
                'password' => self::STRONG_PASSWORD,
                'roles' => [$prize->getKey()],
            ],
            null,
            $admin,
        );
    }

    #[Test]
    public function the_service_creates_nothing_when_the_grant_is_refused(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $prize = $this->escalationRole();

        try {
            app(UserService::class)->create(
                [
                    'name' => 'Puppet Account',
                    'email' => 'puppet.rollback@example.test',
                    'password' => self::STRONG_PASSWORD,
                    'roles' => [$prize->getKey()],
                ],
                null,
                $admin,
            );
        } catch (ActionNotAllowedException) {
            // expected
        }

        $this->assertDatabaseMissing('users', ['email' => 'puppet.rollback@example.test']);
    }

    /*
    |--------------------------------------------------------------------------
    | T12 — `roles` is required only from an actor who may assign them
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_actor_without_users_assign_can_still_save_another_account(): void
    {
        $editor = $this->createUserWithPermissions(
            ['users.view_any', 'users.view', 'users.edit'],
            'admin',
            20,
        );

        $target = $this->createUserWithPermissions([], 'admin', 50);
        $role = $target->roles->first();

        $this->actingAs($editor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), [
                'name' => 'Corrected Name',
                'email' => (string) $target->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/users/'.$target->getKey());

        $target = $target->fresh()->load('roles');

        $this->assertSame('Corrected Name', $target->name);

        $this->assertSame(
            [(int) $role->getKey()],
            $target->roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'An absent `roles` field means "leave the role set alone", never "remove every role".'
        );
    }

    /**
     * And the screen is honest about it: the set is listed, not offered, and the form says it stays.
     */
    #[Test]
    public function the_edit_screen_lists_the_roles_read_only_when_none_can_be_granted(): void
    {
        $editor = $this->createUserWithPermissions(
            ['users.view_any', 'users.view', 'users.edit'],
            'admin',
            20,
        );

        $target = $this->createUserWithPermissions([], 'admin', 50);

        $this->actingAs($editor)
            ->get('/admin/users/'.$target->getKey().'/edit')
            ->assertOk()
            ->assertDontSee('name="roles[]"', false)
            ->assertSee('leaves the roles above exactly as they are')
            ->assertSee((string) $target->roles->first()->displayName());
    }

    /**
     * Relaxing the *requirement* must not relax the *rule*: a hand-rolled payload from the same
     * actor is still refused.
     */
    #[Test]
    public function an_actor_without_users_assign_still_cannot_change_the_role_set(): void
    {
        $editor = $this->createUserWithPermissions(
            ['users.view_any', 'users.view', 'users.edit'],
            'admin',
            20,
        );

        $target = $this->createUserWithPermissions([], 'admin', 50);
        $other = $this->createRoleWithPermissions([], 'admin', 40);

        $this->actingAs($editor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [$other->getKey()]))
            ->assertSessionHasErrors('roles');

        $this->forgetPermissionCache();

        $this->assertFalse($target->fresh()->hasRole($other->name));
    }

    /**
     * An actor who *may* assign still has to name a role — an account with none reaches no panel.
     */
    #[Test]
    public function an_actor_with_users_assign_must_still_name_a_role(): void
    {
        $assigner = $this->createUserWithPermissions(
            ['users.view_any', 'users.view', 'users.edit', 'users.assign', 'roles.assign'],
            'admin',
            20,
        );

        $target = $this->createUserWithPermissions([], 'admin', 50);

        $this->actingAs($assigner)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), [
                'name' => (string) $target->name,
                'email' => (string) $target->email,
            ])
            ->assertSessionHasErrors('roles');
    }

    /*
    |--------------------------------------------------------------------------
    | T13 — one password policy everywhere
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array<int, string>>
     */
    public static function weakPasswords(): array
    {
        return [
            'eight characters' => ['Passw0rd'],
            'nine characters' => ['Passw0rd!'],
            'no upper case' => ['alllowercase1!'],
            'no lower case' => ['NOUPPERLOWER1!'],
            'no symbol' => ['NoSymbols12345'],
            'no number' => ['NoNumbersHere!'],
        ];
    }

    #[Test]
    #[DataProvider('weakPasswords')]
    public function an_administrator_cannot_set_a_password_weaker_than_the_policy(string $weak): void
    {
        $actor = $this->createSuperAdmin();
        $role = Role::query()->where('name', 'Support Agent')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/users/create')
            ->post('/admin/users', [
                'name' => 'Weak Password',
                'email' => 'weak.password@example.test',
                'password' => $weak,
                'password_confirmation' => $weak,
                'status' => 'active',
                'roles' => [$role->getKey()],
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak.password@example.test']);
    }

    #[Test]
    public function an_administrator_cannot_reset_someone_to_a_weak_password_from_the_edit_form(): void
    {
        $actor = $this->createSuperAdmin();
        $target = $this->createUserWithPermissions([], 'admin', 50);

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [
                (int) $target->roles->first()->getKey(),
            ], [
                'password' => 'Passw0rd',
                'password_confirmation' => 'Passw0rd',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertTrue(
            Hash::check('password', (string) $target->fresh()->password),
            'A refused password must leave the existing one in place.'
        );
    }

    #[Test]
    public function a_policy_satisfying_password_is_still_accepted_on_both_forms(): void
    {
        $actor = $this->createSuperAdmin();
        $role = Role::query()->where('name', 'Support Agent')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/users/create')
            ->post('/admin/users', [
                'name' => 'Strong Password',
                'email' => 'strong.password@example.test',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
                'status' => 'active',
                'roles' => [$role->getKey()],
            ])
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'strong.password@example.test')->firstOrFail();

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, (string) $created->password));

        $this->actingAs($actor)
            ->from('/admin/users/'.$created->getKey().'/edit')
            ->put('/admin/users/'.$created->getKey(), $this->payload($created, [(int) $role->getKey()], [
                'password' => 'Vr4#qPs!8Lmz2',
                'password_confirmation' => 'Vr4#qPs!8Lmz2',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Vr4#qPs!8Lmz2', (string) $created->fresh()->password));
    }

    /**
     * Leaving the password blank on the edit form still means "keep the current one" — the policy
     * must not have turned an optional field into a required one.
     */
    #[Test]
    public function an_empty_password_on_the_edit_form_keeps_the_current_one(): void
    {
        $actor = $this->createSuperAdmin();
        $target = $this->createUserWithPermissions([], 'admin', 50);

        $this->actingAs($actor)
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [
                (int) $target->roles->first()->getKey(),
            ], ['password' => '', 'password_confirmation' => '']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('password', (string) $target->fresh()->password));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * The role at the centre of T11: weaker than the Admin by level (30 against 5), carrying two
     * abilities RoleSeeder deliberately withholds from the Admin role.
     *
     * Built directly rather than through the role editor, because the editor already refuses to
     * *create* it as an Admin — the threat is the grant, and the role existing legitimately (seeded,
     * migrated, or made by a Super Admin) is the realistic case.
     */
    private function escalationRole(): Role
    {
        return $this->createRoleWithPermissions(
            ['modules.view_any', 'modules.change_status'],
            'admin',
            30,
            'Module Operator',
        );
    }

    /**
     * The payload the edit form posts: the required fields plus an explicit role set.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $target, array $roleIds, array $overrides = []): array
    {
        return array_merge([
            'name' => (string) $target->name,
            'email' => (string) $target->email,
            'roles' => $roleIds,
        ], $overrides);
    }
}
