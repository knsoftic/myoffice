<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\Student;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may do what to a student record (§66, phase-14-17 §9).
 *
 * **A student with history is never deleted.** Fees, receipts, enrolments and attendance all point
 * here with `restrictOnDelete`, so the database refuses anyway — this policy exists so the screen can
 * explain rather than offering a button that produces a constraint error. Archiving is the status
 * change; `dropped` keeps every row it ever had.
 *
 * **A branch user sees their branch, plus the students who belong to no branch in particular.** Null
 * means "everywhere", not "nobody" ([D-IN-5]).
 *
 * **Money is not here.** A student's fee position is gated by `student_fees.view_financial` (§4.2),
 * not by anything this policy asks — which is why the fee tab on the student screen checks a
 * permission this class never mentions.
 */
final class StudentPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'students';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Student $student): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $student->branch_id === null ? null : (int) $student->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Student $student): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($student)
            && $this->sharesBranch($user, $student->branch_id === null ? null : (int) $student->branch_id);
    }

    /**
     * Only a student nothing was ever recorded against. Anybody who has been charged, paid, enrolled
     * or marked present stays — a record with history is the history.
     */
    public function delete(User $user, Student $student): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $student->hasHistory();
    }

    public function restore(User $user, Student $student): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    /**
     * `forceDelete` is refused for everyone, always. A hard delete would take the fee history with it
     * or fail on a foreign key; neither is something a button should offer.
     */
    public function forceDelete(User $user, Student $student): bool
    {
        return false;
    }

    public function changeStatus(User $user, Student $student): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $this->sharesBranch($user, $student->branch_id === null ? null : (int) $student->branch_id);
    }

    /**
     * Creating the panel login is an `edit`, not a `create`: it changes an existing student record
     * rather than adding one, and the person who maintains student details is the one who does it.
     */
    public function createLogin(User $user, Student $student): bool
    {
        return $this->update($user, $student) && $student->user_id === null;
    }

    /**
     * Merging is a delete in disguise — one of the two records stops existing — so it takes the
     * delete right rather than edit.
     */
    public function merge(User $user, Student $student): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete);
    }

    public function import(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Import);
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
