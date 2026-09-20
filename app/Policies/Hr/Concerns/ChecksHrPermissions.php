<?php

declare(strict_types=1);

namespace App\Policies\Hr\Concerns;

use App\Enums\Ability;
use App\Models\Hr\Employee;
use App\Models\User;
use App\Services\Hr\EmployeeScopeResolver;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared first step of every Phase 7 policy (CLAUDE.md §4, phase-07 §9).
 *
 * Permission names are built from the {@see Ability} enum and a module slug constant rather than typed as
 * string literals, so a renamed ability is a compile-time problem instead of a silent 403. Asking through
 * `$user->can()` re-enters `Gate::before`, which means **a disabled module denies here too, Super Admin
 * included** (D5, §6.10 #15).
 *
 * The order of the two questions is the security property: a missing permission is a plain `false` (403),
 * but holding the permission and failing to reach the **row** is `Response::denyAsNotFound()` — a 404, so
 * an employee id cannot be probed for existence by watching the status code change. That matters more in
 * HR than anywhere else in this system: the ids being probed are people's salaries.
 */
trait ChecksHrPermissions
{
    protected function holds(User $user, string $module, Ability $ability): bool
    {
        return $user->can($module.'.'.$ability->value);
    }

    /**
     * Hold the ability, or 403; then reach the row, or 404.
     */
    protected function reaches(User $user, string $module, Ability $ability, bool $reachesRow): bool|Response
    {
        if (! $this->holds($user, $module, $ability)) {
            return false;
        }

        return $reachesRow ? true : Response::denyAsNotFound();
    }

    protected function isTrashed(?Model $model): bool
    {
        return $model !== null && method_exists($model, 'trashed') && $model->trashed();
    }

    /**
     * Is this employee inside the user's §9 window — everybody, their reporting tree, or just themselves?
     */
    protected function seesEmployee(User $user, ?Employee $employee): bool
    {
        if ($employee === null) {
            return false;
        }

        return app(EmployeeScopeResolver::class)->canSee($user, $employee);
    }

    /**
     * Is this the user's own employee record? Self-service leans on this and on nothing from the request.
     */
    protected function isSelf(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->getKey();
    }

    /**
     * Money on an HR screen is a separate decision from the row itself (§9): a line manager approves
     * absence, not pay, so `view_financial` is asked for on top of `view`.
     */
    protected function seesMoney(User $user, string $module): bool
    {
        return $this->holds($user, $module, Ability::ViewFinancial);
    }
}
