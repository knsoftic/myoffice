<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Enums\ExamStatus;
use App\Models\Institute\Exam;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may set up, schedule, run or cancel an exam (phase-19-23 §9, §4.2).
 *
 * **Setting an exam and marking it are different modules.** Everything here is `exams.*`; entering and
 * publishing marks is `results.*`, so an institute can give a coordinator the calendar without giving
 * them the marks, and an examiner the marks without the calendar.
 *
 * **The publication freeze is not here.** Once results are published, `total_marks`, `passing_marks`,
 * `grade_scale_id` and `scheduled_date` refuse to move — and that refusal lives in the **model**,
 * because `Gate::before` waves a Super Admin past every policy (D124). A rule about what a printed
 * result card was measured against should not depend on who is asking.
 *
 * **`delete` is narrow, and narrower still in the model.** An exam with a result is cancelled, never
 * removed — `restrictOnDelete` catches the hard delete and the model's hook catches the soft one,
 * which is the one a policy alone would have missed.
 */
final class ExamPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'exams';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing the paper. A published exam is still editable here — its name, its instructions, its
     * room — and the four frozen columns are refused by the model rather than by hiding the screen.
     */
    public function update(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($exam)
            && $exam->status !== ExamStatus::Cancelled
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    /** Naming the examiner or the marker (§4.2). */
    public function assign(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && ! $this->isTrashed($exam)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    /** Schedule, conduct, cancel — §4.2 puts all three under one ability. */
    public function changeStatus(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($exam)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    /**
     * **Only an exam nobody has a result for.** The model refuses the rest — including the soft delete
     * that `restrictOnDelete` never sees — and this is the version a screen can read before offering a
     * button that would fail.
     */
    public function delete(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($exam)
            && $exam->results()->doesntExist()
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    public function restore(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore)
            && $this->isTrashed($exam)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewReports(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    public function viewLogs(User $user, Exam $exam): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    /**
     * The second pair of eyes, asked about the **exam** rather than about one row (§2.28.4).
     *
     * **It checks `results.*`, not `exams.*`, even though it hangs off `ExamPolicy`.** Verifying is a
     * marking decision; the class note above says an institute can hand somebody the calendar without
     * the marks. Putting the method here rather than on `ExamResultPolicy` is only about *what it is
     * asked of* — a sheet with no rows yet still has to answer "may this person check it?", and a
     * policy that needs a row cannot.
     *
     * **"Not the person who entered it" is not expressible here**, because that is a fact about the
     * whole sheet. `ExamResultService::verify()` checks it row by row.
     */
    public function verifyResults(User $user, Exam $exam): bool
    {
        return $this->holds($user, ExamResultPolicy::MODULE, Ability::Approve)
            && ! $this->isTrashed($exam)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    /**
     * Publishing the sheet, and withdrawing it again. One ability covers both because they are the
     * same decision taken in opposite directions, and somebody who may release a result to a class
     * must be able to take it back when it turns out to be wrong.
     */
    public function publishResults(User $user, Exam $exam): bool
    {
        return $this->holds($user, ExamResultPolicy::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($exam)
            && $this->sharesBranch($user, $this->branchOf($exam));
    }

    private function branchOf(Exam $exam): ?int
    {
        $branchId = $exam->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
