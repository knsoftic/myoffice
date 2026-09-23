<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Enums\AssignmentStatus;
use App\Models\Institute\Assignment;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may set, publish, close or remove an assignment (phase-19-23 §9, requirement §80).
 *
 * **`delete` is narrow and the service is narrower.** An assignment somebody has submitted to is closed
 * or archived, never deleted — `restrictOnDelete` on `assignment_submissions` holds it at the database
 * and `AssignmentStatus::Closed` exists precisely so that stopping collection never means hiding what
 * everybody was marked on. The check here is the cheap one; the expensive one is the foreign key.
 *
 * **Marking is not here.** Grading lives in `AssignmentSubmissionPolicy` under its own module, because a
 * visiting trainer may be allowed to mark a roster without being allowed to publish new work, and a
 * coordinator the other way round. That split is the whole reason `assignment_submissions` is a module.
 */
final class AssignmentPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'assignments';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing the brief text, the lateness rules and the attempt limits.
     *
     * The three frozen columns — `total_marks`, `deadline_at`, `submission_type` — are refused by the
     * **model** once anything is graded, not here. `Gate::before` waves a Super Admin past every
     * policy, and a rule that restates what a whole class was marked out of is not one that should
     * depend on who is asking.
     */
    public function update(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($assignment)
            && $assignment->status !== AssignmentStatus::Archived
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    public function upload(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Upload)
            && ! $this->isTrashed($assignment)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    /** The brief. A student reaches it through the portal policy, not this one. */
    public function download(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Download)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    /** Publish, close, reopen, archive. */
    public function changeStatus(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($assignment)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    /** Naming the teacher responsible. */
    public function assign(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && ! $this->isTrashed($assignment)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    /**
     * **Only an assignment nobody has submitted to.** This is the version of the rule a screen can
     * read before offering a button that would fail, and it is the one that stops every role except a
     * Super Admin — whom `Gate::before` waves past. The model's `deleting` hook is what stops them.
     */
    public function delete(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($assignment)
            && $assignment->submissions()->doesntExist()
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    public function restore(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore)
            && $this->isTrashed($assignment)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    /** The printable sheet for a physical class (§80). */
    public function print(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    public function viewReports(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewLogs(User $user, Assignment $assignment): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($assignment));
    }

    private function branchOf(Assignment $assignment): ?int
    {
        $branchId = $assignment->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
