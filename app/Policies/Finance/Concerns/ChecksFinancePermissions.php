<?php

declare(strict_types=1);

namespace App\Policies\Finance\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared first step of every Phase 13 policy: the `module.ability` permission (CLAUDE.md §4).
 *
 * Permission names are built from the {@see Ability} enum and the policy's module constant, never typed
 * as string literals, so a renamed ability breaks loudly rather than becoming a silent 403. Going
 * through `$user->can()` re-enters `Gate::before`, so a disabled module denies here too — Super Admin
 * included.
 *
 * The row rules come second. A missing permission is a 403; holding the permission but not reaching the
 * row is a **404**, so ids cannot be probed.
 */
trait ChecksFinancePermissions
{
    protected function holds(User $user, string $module, Ability $ability): bool
    {
        return $user->can($module.'.'.$ability->value);
    }

    /**
     * A soft-deleted row is read-only until it is restored.
     */
    protected function isTrashed(?Model $model): bool
    {
        return $model !== null && method_exists($model, 'trashed') && $model->trashed();
    }
}
