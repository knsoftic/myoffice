<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentCourseProgress;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may mark a syllabus covered (§83, phase-14-17 §9).
 *
 * **There is no `delete`, and the module declares none (§4.2).** Every row here is derived by
 * `CourseProgressService` and re-derivable by `progress:recompute`, so deleting one would destroy
 * nothing and repair nothing. A topic that should stop counting is `skipped` — a `change_status` that
 * leaves a reason on the record and takes the topic's weight out of the denominator.
 *
 * **`create` is the class-level mark and `edit` is the individual one.** Marking a topic covered for
 * a batch is a teaching act; overriding it for one student is a judgement about that student, and the
 * two are grantable apart so a visiting trainer can do the first without the second.
 */
final class StudentProgressPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'student_progress';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, ?StudentCourseProgress $progress = null): bool
    {
        if (! $this->holds($user, self::MODULE, Ability::View)) {
            return false;
        }

        if ($progress === null) {
            return true;
        }

        $branchId = $progress->batch?->branch_id;

        return $this->sharesBranch($user, $branchId === null ? null : (int) $branchId);
    }

    /** Marking a topic covered for a whole batch. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /** Overriding it for one student, and asking for a recompute. */
    public function update(User $user, ?StudentCourseProgress $progress = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ($progress === null || $this->view($user, $progress));
    }

    /** Skipping a topic — which raises everybody's percentage, so it takes a reason. */
    public function changeStatus(User $user, ?StudentCourseProgress $progress = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ($progress === null || $this->view($user, $progress));
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    /**
     * A derived row is never deleted, and the module declares no such ability (§4.2). Answered here
     * so the gate is deliberate rather than an unhandled ability.
     */
    public function delete(User $user, StudentCourseProgress $progress): bool
    {
        return false;
    }
}
