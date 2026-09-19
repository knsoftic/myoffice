<?php

declare(strict_types=1);

namespace App\Policies\Cms\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The standard permission map of a phase-04 content policy (§6.11): each policy action defers to the
 * `{module}.{ability}` permission first (CLAUDE.md §4) and only then adds a row condition.
 *
 *   viewAny → view_any      view → view         create → create
 *   update  → edit          delete → delete     restore → restore (trashed rows only)
 *   changeStatus → change_status                reorder → edit
 *   upload → upload         download → download export → export     print → print
 *
 * A trashed row is read-only until restored. `forceDelete` is refused: no phase-04 content screen
 * hard-deletes (the job-application policy, which has a force-delete route, overrides it).
 *
 * The permission names come from the `Ability` enum and the using policy's `MODULE` constant — never a
 * string literal — and `Gate::before` still denies everything, Super Admin included, when the module is
 * disabled. Policies whose rows are scoped per user (blog posts, job applications, contact inquiries)
 * override the row-level methods.
 */
trait AuthorizesContentModule
{
    use ChecksCmsPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($model);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Publish / unpublish / archive, activate / deactivate, feature toggles (§7.2 `status` routes).
     */
    public function changeStatus(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($model);
    }

    /**
     * The drag-and-drop `reorder` endpoints (§7.2): `{module}.edit`. Class-level.
     */
    public function reorder(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    public function upload(User $user): bool
    {
        return $this->allows($user, Ability::Upload);
    }

    public function download(User $user): bool
    {
        return $this->allows($user, Ability::Download);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, Ability::Print);
    }
}
