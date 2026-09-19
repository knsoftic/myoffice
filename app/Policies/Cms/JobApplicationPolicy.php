<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\JobApplication;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and move job applications (phase-04 §4, §9.1.3):
 * `job_applications` = `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `download` + `export` + `print`.
 *
 * **Row scope (§9.1.3, F-12.4).** `job_applications.view_any` (HR) reaches every application. Anyone
 * else reaches only the applications assigned to it **or** made to an opening it created
 * (`job_openings.created_by`) — the hiring-manager scope, with no new column and no new ability. It is the
 * same rule as `JobApplication::scopeVisibleTo()`, which every list query uses.
 *
 * **Failure codes.** Missing the permission is a 403. Holding the permission but not the row is a
 * **404** (`Response::denyAsNotFound()`): an application id — and the candidate behind it — must not be
 * probeable (resolutions §8 row 5).
 *
 * `update()` is internal notes + rating only; a reviewer who fails it never sees the notes, the rating
 * or the CV link (§9.1.3). The CV itself is served only by `admin.job-applications.cv`, which re-runs
 * `download()` on every request (D21).
 */
final class JobApplicationPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'job_applications';

    /**
     * The index lists `visibleTo()`, so a reviewer holding only `view` gets its own slice.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny)
            || $this->allows($user, Ability::View);
    }

    public function view(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::View)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    /**
     * `admin.job-applications.update` — internal notes and rating only.
     */
    public function update(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::Edit) || $this->isTrashed($application)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    /**
     * Rendering `internal_notes` and `rating` (§9.1.3): the same test as `update()`.
     */
    public function viewInternalNotes(User $user, JobApplication $application): bool
    {
        return $this->update($user, $application) === true;
    }

    public function delete(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::Delete) || $this->isTrashed($application)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    /**
     * `admin.job-applications.force-destroy` — `job_applications.delete`; the model removes the CV file
     * on `forceDeleted`. Also the only way to let the same address re-apply (§12.1 R4).
     */
    public function forceDelete(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::Delete)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    public function restore(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::Restore) || ! $this->isTrashed($application)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    /**
     * Move a candidate along the six-stage pipeline. An illegal transition is the Form Request's 422.
     */
    public function changeStatus(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::ChangeStatus) || $this->isTrashed($application)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    /**
     * Set `assigned_to`. Whether the chosen reviewer may hold the row is `AssignRequest`'s rule.
     */
    public function assign(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::Assign) || $this->isTrashed($application)) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    /**
     * `admin.job-applications.cv` — the only path to the private file (D21), re-checked per request.
     */
    public function download(User $user, JobApplication $application): Response|bool
    {
        if (! $this->allows($user, Ability::Download) || ! $application->hasCv()) {
            return false;
        }

        return $this->reaches($user, $application);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, Ability::Print);
    }

    /**
     * §9.1.3: `view_any`, or assigned to the user, or an application to an opening the user created.
     */
    private function reaches(User $user, JobApplication $application): Response|bool
    {
        if ($this->allows($user, Ability::ViewAny)
            || $application->isAssignedTo($user)
            || $application->belongsToOpeningOwnedBy($user)) {
            return true;
        }

        return Response::denyAsNotFound();
    }
}
