<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentAdmission;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;
use App\Support\Money;

/**
 * Who may do what to an admission (§69, phase-14-17 §9).
 *
 * **The permission opens the door; the pipeline decides what is behind it.** A terminal admission is
 * read-only, and an admission whose figures are locked is read-only in the part that matters — the
 * money. `updateFigures` is a separate ability from `update` for exactly that reason: correcting a
 * counsellor's name and changing what a student was charged are not the same act, and after the first
 * charge the second one is not an act at all (INV-I2).
 *
 * **`view_financial` gates the figures, not the record.** A coordinator may see that a student is
 * admitted to a course without seeing what they agreed to pay; the screen renders the row and omits
 * the money columns entirely rather than showing them blanked, which is the Phase 13 discipline
 * (`FinanceVisibility`) applied here.
 */
final class StudentAdmissionPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'admissions';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentAdmission $admission): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $admission->branch_id === null ? null : (int) $admission->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, StudentAdmission $admission): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($admission)
            && $admission->stage->isLive()
            && $this->sharesBranch($user, $admission->branch_id === null ? null : (int) $admission->branch_id);
    }

    /**
     * The agreed figures, which freeze the moment Phase 18 issues the first charge.
     */
    public function updateFigures(User $user, StudentAdmission $admission): bool
    {
        return $this->update($user, $admission) && ! $admission->figuresAreLocked();
    }

    /**
     * Never once anything has been charged: a deleted admission with fee rows against it is a hole in
     * the ledger. The FK from `student_fees` refuses too; this is what lets the screen say so.
     */
    public function delete(User $user, StudentAdmission $admission): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $admission->figuresAreLocked()
            && Money::compare((string) $admission->charged_amount, Money::ZERO) <= 0;
    }

    public function restore(User $user, StudentAdmission $admission): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function forceDelete(User $user, StudentAdmission $admission): bool
    {
        return false;
    }

    /** Register, activate, complete, cancel, withdraw — one right for the whole pipeline. */
    public function changeStatus(User $user, StudentAdmission $admission): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $this->sharesBranch($user, $admission->branch_id === null ? null : (int) $admission->branch_id);
    }

    /**
     * Cancelling is refused while money has cleared — the service says the same thing with the amount
     * in it, and this is what stops the button rendering in the first place.
     */
    public function cancel(User $user, StudentAdmission $admission): bool
    {
        return $this->changeStatus($user, $admission)
            && $admission->stage->isLive()
            && Money::compare((string) $admission->paid_amount, Money::ZERO) <= 0;
    }

    public function withdraw(User $user, StudentAdmission $admission): bool
    {
        return $this->changeStatus($user, $admission) && $admission->stage->isLive();
    }

    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function print(User $user, StudentAdmission $admission): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print) && $this->view($user, $admission);
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
