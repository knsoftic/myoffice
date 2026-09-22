<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Enums\StudentFeeStatus;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;
use App\Support\Money;

/**
 * Who may raise, reduce, cancel or read a fee charge (phase-18 §9).
 *
 * **Money columns are a second permission, not a second screen.** `student_fees.view` gets somebody
 * the charge; `student_fees.view_financial` gets them the amounts. That split is what lets a Course
 * Coordinator see that a student has an outstanding fee without seeing what the institute charged them
 * — and it is enforced in the query and the payload, never by hiding a column in the template.
 *
 * **`delete` is deliberately narrow.** A charge with a receipt against it is never removed: the money
 * arrived, and a row that disappears takes the evidence with it. Cancelling is the supported act, and
 * the service refuses even that while a non-voided receipt exists. The soft delete stays for a charge
 * raised entirely in error before anybody paid anything.
 *
 * **A student's own charge is reached through `StudentPortalPolicy`, not here**, and a charge that is
 * not theirs is a **404** rather than a 403 (§9) — an id that answers differently depending on whether
 * it exists is an id somebody can enumerate.
 */
final class StudentFeePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'student_fees';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentFee $fee): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $fee->branch_id === null ? null : (int) $fee->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing the charge itself — its title, notes and due date. **Not its amounts**: a gross that
     * moved after a commission was computed from it would silently change what a partner earned, which
     * is why the admission's figures lock on the first charge and a correction is a discount row.
     */
    public function update(User $user, StudentFee $fee): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($fee)
            && $fee->status !== StudentFeeStatus::Cancelled
            && $this->sharesBranch($user, $fee->branch_id === null ? null : (int) $fee->branch_id);
    }

    /**
     * Cancel and reopen. The service adds the rule this cannot know: a charge holding a receipt is
     * refused whoever asks, because the correct act there is a refund.
     */
    public function changeStatus(User $user, StudentFee $fee): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($fee)
            && $this->sharesBranch($user, $fee->branch_id === null ? null : (int) $fee->branch_id);
    }

    /**
     * Only a charge nobody has ever paid anything against, and only with the ability.
     *
     * The check is on the cache rather than on a count of receipts because `paid_amount` is recomputed
     * from the receipts under the charge's row lock — if the two ever disagree, that is a drift the
     * nightly verifier reports, and until then the cache is the one the whole system reads.
     */
    public function delete(User $user, StudentFee $fee): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($fee)
            && Money::isZero((string) $fee->paid_amount)
            && $this->sharesBranch($user, $fee->branch_id === null ? null : (int) $fee->branch_id);
    }

    public function restore(User $user, StudentFee $fee): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($fee);
    }

    /** The amounts. Without it a screen shows the charge and not what it is for. */
    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    /** The collection desk's aggregates and the three dashboard widgets (§8.8, §8.10). */
    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }
}
