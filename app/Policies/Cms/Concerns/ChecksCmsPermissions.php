<?php

declare(strict_types=1);

namespace App\Policies\Cms\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared first step of every Phase 3 policy: the `module.ability` permission (CLAUDE.md §4, D4).
 *
 * The permission name is built from the {@see Ability} enum and the policy's own `MODULE` constant, never
 * spelled as a string literal, so a renamed ability is a compile-time problem rather than a silent 403.
 * Checking through `$user->can('<module>.<ability>')` also re-enters `Gate::before`, so a disabled module
 * denies here even when the outer policy-style check could not resolve its module (class-string checks
 * such as `viewAny`).
 *
 * Only after the permission passes does a policy add its structural rule (INV-7 required sections,
 * `is_system` pages, `usage_count` guards). `Gate::before` still waves a Super Admin through before any
 * policy runs, which is why every such rule is repeated in the owning service.
 */
trait ChecksCmsPermissions
{
    /**
     * Does the user hold `{module}.{ability}`? `$module` defaults to the policy's `MODULE`.
     */
    protected function allows(User $user, Ability $ability, ?string $module = null): bool
    {
        return $user->can(($module ?? self::MODULE).'.'.$ability->value);
    }

    /**
     * A soft-deleted row is read-only until it is restored.
     */
    protected function isTrashed(Model $model): bool
    {
        return method_exists($model, 'trashed') && $model->trashed();
    }
}
