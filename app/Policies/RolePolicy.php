<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\ChecksRoleHierarchy;

/**
 * Who may manage roles and their permission matrix.
 *
 * Rules (phase-01 §6, §10):
 *   - the matching `roles.*` permission is required first;
 *   - `is_system` roles (Super Admin, Admin) can never be updated, renamed or deleted;
 *   - a role can only be touched when its level is strictly weaker than the actor's best level,
 *     which also means you can never edit, delete or hand out your own role.
 */
final class RolePolicy
{
    use ChecksRoleHierarchy;

    private const MODULE = 'roles';

    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::ViewAny));
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::View));
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::Create));
    }

    public function update(User $user, Role $role): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::Edit))) {
            return false;
        }

        if ($this->isProtected($role)) {
            return false;
        }

        return $this->outranksRole($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::Delete))) {
            return false;
        }

        if ($this->isProtected($role)) {
            return false;
        }

        return $this->outranksRole($user, $role);
    }

    /**
     * Roles are not soft-deleted, so a hard delete is simply `delete`.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        return $this->delete($user, $role);
    }

    /**
     * Edit the permission matrix of a role — an update, so the same protections apply.
     */
    public function managePermissions(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    /**
     * Grant this role to a user. Allowed for system roles (an Admin role must stay grantable)
     * but only by someone who outranks the role.
     */
    public function assign(User $user, ?Role $role = null): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::Assign))) {
            return false;
        }

        return $role === null || $this->outranksRole($user, $role);
    }

    /**
     * `is_system` blocks rename/delete. The column is the source of truth; Role::isProtected()
     * wraps it (phase-01 §3).
     */
    private function isProtected(Role $role): bool
    {
        if (method_exists($role, 'isProtected')) {
            return (bool) $role->isProtected();
        }

        return (bool) ($role->is_system ?? false);
    }
}
