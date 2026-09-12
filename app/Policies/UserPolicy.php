<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\ChecksRoleHierarchy;
use Illuminate\Support\Facades\Gate;

/**
 * Who may manage user accounts.
 *
 * Every method checks the matching `users.*` permission first, then the rank rule: you can only
 * touch accounts that are strictly weaker than your own best role level, and you can never delete
 * yourself. Viewing and editing your *own* row is always allowed once you hold the permission.
 *
 * Note: `Gate::before` grants Super Admin everything, so these rules shape what *other* roles can
 * do; the self-deletion guard must therefore be repeated in the controller/service that deletes a
 * user, because a Super Admin never reaches this policy.
 */
final class UserPolicy
{
    use ChecksRoleHierarchy;

    private const MODULE = 'users';

    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::ViewAny));
    }

    public function view(User $user, User $model): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::View))) {
            return false;
        }

        return $this->isSelf($user, $model) || $this->outranks($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::Create));
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::Edit))) {
            return false;
        }

        return $this->isSelf($user, $model) || $this->outranks($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        // A user may never delete themselves.
        if ($this->isSelf($user, $model)) {
            return false;
        }

        if (! $user->can($this->permission(self::MODULE, Ability::Delete))) {
            return false;
        }

        return $this->outranks($user, $model);
    }

    public function restore(User $user, User $model): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::Restore))) {
            return false;
        }

        return $this->outranks($user, $model);
    }

    /**
     * Hard deletes are refused outright: user rows are soft-deleted and kept for audit history.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Activate / deactivate / suspend — never your own account.
     */
    public function changeStatus(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return false;
        }

        if (! $user->can($this->permission(self::MODULE, Ability::ChangeStatus))) {
            return false;
        }

        return $this->outranks($user, $model);
    }

    /**
     * Force a new password on someone else's account (never your own — use change-password).
     */
    public function resetPassword(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return false;
        }

        if (! $user->can($this->permission(self::MODULE, Ability::Edit))) {
            return false;
        }

        return $this->outranks($user, $model);
    }

    /**
     * Attach or detach roles. A role may only be granted when the actor outranks both the target
     * user and the role itself, so nobody can hand out power they do not have.
     *
     * This is the single enforcement point for a role grant — `StoreUserRequest` and
     * `UpdateUserRequest` both ask the Gate for it (through `ValidatesRoleAssignment`), and
     * `UserController` filters the role checkboxes with the same question, so the form can never
     * offer a grant the server would refuse.
     *
     * Two things fall out of `outranks()` being strict:
     *   · a peer cannot be managed, and
     *   · **you are never your own superior**, so nobody edits their own role set. (`Gate::before`
     *     still waves a Super Admin through, which is why `UpdateUserRequest` and `UserService`
     *     repeat the self-check outside the Gate.)
     *
     * A not-yet-created account (`$model->exists === false`) has no roles and therefore no rank to
     * outrank; `users.create` plus the role's own level is the whole rule there.
     */
    public function assignRoles(User $user, User $model, ?Role $role = null): bool
    {
        if (! $user->can($this->permission(self::MODULE, Ability::Assign))) {
            return false;
        }

        if ($model->exists && ! $this->outranks($user, $model)) {
            return false;
        }

        // The role half of the rule already has a home: `roles.assign` plus the role's level, in
        // RolePolicy::assign(). Evaluated for *this* actor, not for whoever is signed in, so the
        // policy stays usable outside a request.
        return $role === null || Gate::forUser($user)->allows('assign', $role);
    }

    public function export(User $user): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::Export));
    }

    public function print(User $user): bool
    {
        return $user->can($this->permission(self::MODULE, Ability::Print));
    }
}
