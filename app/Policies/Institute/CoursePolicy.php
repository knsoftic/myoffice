<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may do what to a course (§62, phase-14-17 §4.2, §9).
 *
 * The permission opens the door; the **course's own history** decides what is behind it:
 *
 * - **A course anybody has ever been admitted to is never deleted.** It is archived, and it keeps every
 *   batch, admission and fee row that pointed at it. The FKs refuse the delete; the policy is what lets
 *   the screen explain instead of showing a button that produces a database error.
 * - **`view_financial` is a separate right.** It gates the three fee columns everywhere a course is
 *   rendered — the index, the form, the export and the public page — so a coordinator can build a
 *   syllabus without being shown the price list.
 * - **A branch user sees their branch and the courses that belong to every branch.** `branch_id = null`
 *   means "offered everywhere", so it is visible to everyone rather than to nobody.
 */
final class CoursePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'courses';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Course $course): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $course->branch_id === null ? null : (int) $course->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Course $course): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($course)
            && $this->sharesBranch($user, $course->branch_id === null ? null : (int) $course->branch_id);
    }

    /**
     * Only a course nothing was ever sold against — and an archived one is kept, not removed, so the
     * history it carries stays readable.
     */
    public function delete(User $user, Course $course): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $course->status !== CourseStatus::Archived
            && ! $course->hasSalesHistory();
    }

    public function restore(User $user, Course $course): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    /**
     * Publish, unpublish, archive, revive and feature — one right for the whole ladder, because they
     * are the same decision seen from different rungs.
     */
    public function changeStatus(User $user, Course $course): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $this->sharesBranch($user, $course->branch_id === null ? null : (int) $course->branch_id);
    }

    /**
     * Publishing additionally needs a course that is ready — the completeness guard of §2.30.1, asked
     * here so the button can be disabled with a reason rather than failing on submit.
     */
    public function publish(User $user, Course $course): bool
    {
        return $this->changeStatus($user, $course) && $course->publishingGaps() === [];
    }

    public function duplicate(User $user, Course $course): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create) && $this->view($user, $course);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * The three fee columns, everywhere they are rendered.
     */
    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function viewLogs(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs);
    }
}
