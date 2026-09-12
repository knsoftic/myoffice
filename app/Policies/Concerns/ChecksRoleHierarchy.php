<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Ability;
use App\Models\Role;
use App\Models\User;

/**
 * Shared rules for the authorization policies.
 *
 * `roles.level` is a ranking where **lower is more powerful** (Super Admin = 1). A user's "best
 * level" is the lowest level among their roles. Anyone may only manage users and roles that sit
 * *strictly* below them (a greater level number), which means peers cannot manage each other and
 * nobody can promote themselves.
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
     * `roles.level` as an int, tolerating a missing or non-numeric value.
     */
    private function levelValue(object $role): ?int
    {
        $level = $role->level ?? null;

        return is_numeric($level) ? (int) $level : null;
    }
}
