<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\CourseInquiry;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may work a course enquiry (§86, phase-14-17 §9).
 *
 * **A converted enquiry is read-only.** Once it has produced a student, its status is the record of
 * how that student arrived; editing it afterwards would rewrite the funnel the §88 report counts.
 *
 * **`assign` is its own right.** Handing an enquiry to another counsellor changes who is answerable
 * for calling somebody back, which is a management act rather than part of working the queue.
 */
final class CourseInquiryPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'course_inquiries';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, CourseInquiry $inquiry): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $inquiry->branch_id === null ? null : (int) $inquiry->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, CourseInquiry $inquiry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($inquiry)
            && ! $inquiry->status->isWon()
            && $this->sharesBranch($user, $inquiry->branch_id === null ? null : (int) $inquiry->branch_id);
    }

    /** Logging a call is `edit`: it is the ordinary work of the queue. */
    public function logFollowUp(User $user, CourseInquiry $inquiry): bool
    {
        return $this->update($user, $inquiry);
    }

    public function changeStatus(User $user, CourseInquiry $inquiry): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $inquiry->status->isWon()
            && $this->sharesBranch($user, $inquiry->branch_id === null ? null : (int) $inquiry->branch_id);
    }

    public function assign(User $user, CourseInquiry $inquiry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && ! $inquiry->status->isWon();
    }

    /**
     * An enquiry that produced a student is not deletable: it is the first step of that student's
     * story, and the §88 conversion rate divides by it.
     */
    public function delete(User $user, CourseInquiry $inquiry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $inquiry->converted_student_id === null
            && $inquiry->converted_application_id === null;
    }

    public function restore(User $user, CourseInquiry $inquiry): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    /** Promoting to an application crosses into the other module, so it asks that module. */
    public function promote(User $user, CourseInquiry $inquiry): bool
    {
        return $this->view($user, $inquiry)
            && $inquiry->status->isOpen()
            && $this->holds($user, 'student_applications', Ability::Create);
    }

    /** The walk-in shortcut: straight to a student and an admission, so it asks for both. */
    public function convert(User $user, CourseInquiry $inquiry): bool
    {
        return $this->view($user, $inquiry)
            && $inquiry->status->isOpen()
            && $this->holds($user, 'students', Ability::Create)
            && $this->holds($user, 'admissions', Ability::Create);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewLogs(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs);
    }
}
