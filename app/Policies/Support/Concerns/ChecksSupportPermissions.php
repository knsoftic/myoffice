<?php

declare(strict_types=1);

namespace App\Policies\Support\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared first step of every Phase 22 policy: the `module.ability` permission (CLAUDE.md §4).
 *
 * The same shape as `ChecksInstitutePermissions`, and deliberately a separate trait rather than a
 * shared one: the two live in different namespaces, and a policy trait that spanned both would be a
 * thing every later phase had to be told not to widen. Names are built from the {@see Ability} enum
 * and the policy's module constant, never typed as literals, so a renamed ability breaks loudly
 * instead of becoming a silent 403.
 *
 * **`$user->can()` re-enters `Gate::before`**, so a disabled module denies here too — Super Admin
 * included. What it does *not* do is enforce an invariant: the gate waves a Super Admin past every
 * policy, so "a ticket is never deleted" and "a sent message is never edited" live in the models
 * (D124, D140). A policy decides who may try an ordinary thing.
 *
 * **A missing permission is a 403; holding the permission but not reaching the row is a 404**, so
 * ids cannot be probed — somebody who may see tickets must not be able to learn that ticket 8412
 * exists by watching which error comes back.
 */
trait ChecksSupportPermissions
{
    protected function holds(User $user, string $module, Ability $ability): bool
    {
        return $user->can($module.'.'.$ability->value);
    }

    /** A soft-deleted row is read-only until it is restored. */
    protected function isTrashed(?Model $model): bool
    {
        return $model !== null && method_exists($model, 'trashed') && $model->trashed();
    }

    /**
     * §9's branch rule (D11): a row with no branch belongs to everyone, and a user with no branch
     * sees every row. Only a user *with* a branch is narrowed, and only against a row that has one.
     *
     * A client, a collaborator and an external guest are **not** branch-bound (§9.4), which this
     * gets right by accident of shape rather than by a special case: none of them holds a
     * `users.branch_id`.
     */
    protected function sharesBranch(User $user, ?int $rowBranchId): bool
    {
        $userBranch = $user->branch_id === null ? null : (int) $user->branch_id;

        return $userBranch === null || $rowBranchId === null || $rowBranchId === $userBranch;
    }

    protected function idOf(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
