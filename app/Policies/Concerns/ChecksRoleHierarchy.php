<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Ability;
use App\Models\Role;
use App\Models\User;
use Throwable;

/**
 * Shared rules for the authorization policies.
 *
 * `roles.level` is a ranking where **lower is more powerful** (Super Admin = 1). A user's "best
 * level" is the lowest level among their roles. Anyone may only manage users and roles that sit
 * *strictly* below them (a greater level number), which means peers cannot manage each other and
 * nobody can promote themselves.
 *
 * The rank is only half of a role grant, though. `roles.level` is a number somebody types into a
 * form: it says nothing about what the role can *do*. The second half — you may not hand out an
 * ability you do not hold yourself — is {@see self::holdsEveryPermissionOf()} (T11).
 *
 * Permission names are always built from the module slug plus an Ability case, never typed as a
 * string literal, so a renamed ability is a compile-time problem instead of a silent 403.
 */
trait ChecksRoleHierarchy
{
    /** Weaker than any real role (roles.level is an unsignedSmallInteger). */
    private const WEAKEST_LEVEL = 65535;

    /**
     * "{module}.{ability}" — the one permission naming rule (CLAUDE.md §3, phase-01 §4).
     */
    protected function permission(string $module, Ability $ability): string
    {
        return $module.'.'.$ability->value;
    }

    /**
     * Is the actor the same row as the target?
     */
    protected function isSelf(User $user, User $target): bool
    {
        return $user->getKey() !== null && $user->getKey() === $target->getKey();
    }

    /**
     * Lowest (most powerful) level among the user's roles; WEAKEST_LEVEL when they hold none.
     */
    protected function bestLevel(User $user): int
    {
        $levels = $user->roles
            ->map(fn (object $role): ?int => $this->levelValue($role))
            ->reject(static fn (?int $level): bool => $level === null);

        return $levels->isEmpty() ? self::WEAKEST_LEVEL : (int) $levels->min();
    }

    /**
     * Level of a single role row.
     */
    protected function levelOfRole(Role $role): int
    {
        return $this->levelValue($role) ?? self::WEAKEST_LEVEL;
    }

    /**
     * May the actor manage this user? True only when the target is strictly weaker.
     */
    protected function outranks(User $user, User $target): bool
    {
        return $this->bestLevel($target) > $this->bestLevel($user);
    }

    /**
     * May the actor manage this role? True only when the role is strictly weaker than the actor.
     */
    protected function outranksRole(User $user, Role $role): bool
    {
        return $this->levelOfRole($role) > $this->bestLevel($user);
    }

    /**
     * Does the actor hold **every** permission this role carries?
     *
     * The role *editor* has always refused to grant a permission the actor does not hold
     * (`ValidatesPermissionGrants`). A role *grant* was bounded only by `roles.level`, so the
     * first custom role with a level below the actor's that happened to carry, say, `modules.*`
     * would let an Admin escalate through a puppet account: create the account, grant it the role,
     * sign in as it. Nothing in the seeded data allows that today, which is exactly why it had to
     * be closed before somebody adds the role that does (DEVELOPMENT_LOG §8 **T11**).
     *
     * Two deliberate choices:
     *
     *   · A **Super Admin** is excepted. They hold the whole permissions table by definition, and
     *     `Gate::before` returns true for them long before a policy is consulted, so the exception
     *     only matters when the policy is invoked directly (a console command, a test).
     *   · The actor's own grants are read straight off their role and direct permission
     *     relations — what the database records they were given — and never through `can()`.
     *     `can()` runs `Gate::before`, which denies every ability of a *disabled* module; an
     *     administrator who genuinely holds `projects.edit` must still be able to hand out a role
     *     carrying it while the Projects module is switched off, because a grant is about future
     *     access rather than access right now. `ValidatesPermissionGrants` makes the same choice
     *     for the same reason.
     */
    protected function holdsEveryPermissionOf(User $user, Role $role): bool
    {
        try {
            if ($user->isSuperAdmin()) {
                return true;
            }

            $required = $this->permissionNamesOf($role);

            if ($required === []) {
                return true;
            }

            $held = $this->permissionNamesHeldBy($user);

            foreach ($required as $name) {
                if (! array_key_exists($name, $held)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            // The question could not be answered — refuse the grant. A security check fails
            // closed, never open.
            return false;
        }
    }

    /**
     * Permission names the role carries.
     *
     * @return array<int, string>
     */
    private function permissionNamesOf(Role $role): array
    {
        return $this->names($role->permissions);
    }

    /**
     * Permission names the actor holds, through their roles or directly, as a lookup map.
     *
     * Deliberately **not** `getAllPermissions()`: that merges two model collections, `sort()`s the
     * result by comparing Eloquent objects and re-indexes it, on *every* call — and this question is
     * asked once per candidate role while the user form filters the role list. Measured against the
     * seeded data (an Admin actor holding 778 permissions, eighteen candidate roles) the plain loop
     * below is roughly half the cost of eighteen `getAllPermissions()` calls, and only the names are
     * needed.
     *
     * The relations are loaded without a column restriction — exactly the ones
     * `getAllPermissions()` loads — so a caller that later reads `$user->permissions` (the effective
     * permission grid on the user screen, for one) still gets whole models rather than two columns.
     *
     * @return array<string, true>
     */
    private function permissionNamesHeldBy(User $user): array
    {
        $user->loadMissing(['permissions', 'roles', 'roles.permissions']);

        $names = [];

        foreach ($user->permissions as $permission) {
            $name = (string) ($permission->name ?? '');

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $name = (string) ($permission->name ?? '');

                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @param  iterable<mixed>  $permissions
     * @return array<int, string>
     */
    private function names(iterable $permissions): array
    {
        $names = [];

        foreach ($permissions as $permission) {
            $name = is_object($permission) ? (string) ($permission->name ?? '') : (string) $permission;

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * `roles.level` as an int, tolerating a missing or non-numeric value.
     */
    private function levelValue(object $role): ?int
    {
        $level = $role->level ?? null;

        return is_numeric($level) ? (int) $level : null;
    }
}
