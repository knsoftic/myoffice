<?php

declare(strict_types=1);

namespace App\Policies\Crm\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared first step of every Phase 5 policy: the `module.ability` permission (CLAUDE.md §4, D4).
 *
 * Permission names are built from the {@see Ability} enum and a module slug constant, never typed as string
 * literals, so a renamed ability is a compile-time problem rather than a silent 403. Checking through
 * `$user->can('<module>.<ability>')` re-enters `Gate::before`, so a disabled module denies here too — for Super
 * Admin as well (§11 test 11).
 *
 * The row rules come second: a missing permission is a 403 (`false`); holding the permission without reaching the
 * row is a **404** (`Response::denyAsNotFound()`), so ids cannot be probed (resolutions §8 row 5).
 */
trait ChecksCrmPermissions
{
    /**
     * Does the user hold `{module}.{ability}`?
     */
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
