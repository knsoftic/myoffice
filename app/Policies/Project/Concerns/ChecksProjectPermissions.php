<?php

declare(strict_types=1);

namespace App\Policies\Project\Concerns;

use App\Enums\Ability;
use App\Enums\ProjectMemberRole;
use App\Models\Project\Project;
use App\Models\Project\ProjectMember;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared first step of every Phase 6 policy (CLAUDE.md §4, phase-06 §9).
 *
 * Permission names are built from the {@see Ability} enum and a module slug constant rather than typed as
 * string literals, so a renamed ability is a compile-time problem instead of a silent 403. Checking through
 * `$user->can()` re-enters `Gate::before`, so a **disabled module denies here too — Super Admin included**
 * (D5, P6-56).
 *
 * The order the two questions are asked in is the security property (INV-P15): a missing permission is a
 * plain `false` (403), but holding the permission and failing to reach the **row** is
 * `Response::denyAsNotFound()` — a 404, so an id cannot be probed for existence by watching the status code
 * change.
 */
trait ChecksProjectPermissions
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
     * Can this user see the project at all (§9's `Project::visibleTo()`, asked for one row)?
     */
    protected function seesProject(User $user, ?Project $project): bool
    {
        if ($project === null) {
            return false;
        }

        if ($user->can('projects.view_any')) {
            return true;
        }

        return Project::query()
            ->withoutGlobalScopes()
            ->whereKey($project->getKey())
            ->visibleTo($user)
            ->exists();
    }

    /**
     * Does this user run the project from inside it — manager, or a membership role that can manage?
     */
    protected function managesProject(User $user, ?Project $project): bool
    {
        if ($project === null) {
            return false;
        }

        if ((int) $project->project_manager_id === (int) $user->getKey()) {
            return true;
        }

        return ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->whereIn('role', [ProjectMemberRole::Manager->value, ProjectMemberRole::Lead->value])
            ->exists();
    }
}
