<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\AssignmentSubmission;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may read, mark or amend a student's work (phase-19-23 §9, §4.1).
 *
 * **Grading is not authoring, which is why this module exists at all.** A visiting trainer may be
 * allowed to read a roster's submissions without marking them; a coordinator may mark without being
 * able to publish new assignments. Neither is expressible while both live under `assignments.edit`.
 * Here, `edit` **is** the mark-and-feedback ability.
 *
 * **`delete` and `forceDelete` return false for every role, including Super Admin.** The ability is not
 * registered, so nobody can be granted it by mistake, and the model's `deleting` hook refuses the act
 * even when `Gate::before` has already waved a Super Admin past this policy. Marked work is somebody's
 * record; the supported acts are superseding it and returning it, both of which keep the row.
 *
 * **A student's own submission is reached through the portal, not here**, and another student's id is a
 * **404** rather than a 403 — an id that answers differently depending on whether it exists is an id
 * somebody can enumerate.
 */
final class AssignmentSubmissionPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'assignment_submissions';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, AssignmentSubmission $submission): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($submission));
    }

    /** Recording an offline submission on a student's behalf (§4.1) — and nothing else. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Marking and feedback. A superseded attempt is refused: the current one is what gets marked, and
     * the service says so again with a message naming why.
     */
    public function update(User $user, AssignmentSubmission $submission): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && $submission->isLive()
            && $this->sharesBranch($user, $this->branchOf($submission));
    }

    /**
     * Changing a mark the student has already seen. Same ability as marking, and the service adds what
     * a policy cannot: a mandatory reason and an activity row carrying old and new.
     */
    public function amend(User $user, AssignmentSubmission $submission): bool
    {
        return $this->update($user, $submission) && $submission->status->isGraded();
    }

    /** Releasing marks, and running the missed sweep. */
    public function changeStatus(User $user, AssignmentSubmission $submission): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $this->sharesBranch($user, $this->branchOf($submission));
    }

    /** A student's files, and a teacher's feedback file. */
    public function download(User $user, AssignmentSubmission $submission): bool
    {
        return $this->holds($user, self::MODULE, Ability::Download)
            && $this->sharesBranch($user, $this->branchOf($submission));
    }

    /**
     * **Never.** Not for any role, not for a Super Admin, not with any ability — the ability is not
     * registered and the model refuses the act regardless. See the class note.
     */
    public function delete(User $user, AssignmentSubmission $submission): bool
    {
        return false;
    }

    /** Never — same reason. */
    public function forceDelete(User $user, AssignmentSubmission $submission): bool
    {
        return false;
    }

    /** Never — there is nothing to restore, because nothing is ever deleted. */
    public function restore(User $user, AssignmentSubmission $submission): bool
    {
        return false;
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewLogs(User $user, AssignmentSubmission $submission): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($submission));
    }

    /**
     * A submission carries no branch of its own; it inherits the assignment's, which was copied from
     * the batch. Reading it through the relation keeps one answer rather than a denormalised second.
     */
    private function branchOf(AssignmentSubmission $submission): ?int
    {
        $branchId = $submission->assignment?->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
