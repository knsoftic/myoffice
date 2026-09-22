<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentFeeDiscount;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may reduce what a student owes (phase-18 §6.5, §9).
 *
 * **`update` and `delete` return false for everybody, always.** A discount row is append-only: the
 * database refuses a DELETE through `trg_sfd_no_delete` and the model refuses an UPDATE through
 * `FinancialRow`, and this is where those refusals get a sentence instead of an SQLSTATE. The reason
 * they exist at all is that the net fee on the day a payment arrived has to stay answerable — a
 * commission was computed from it, and money may already have moved.
 *
 * The only correction is a `reversal` row pointing at the original, which needs `approve` rather than
 * `edit`: undoing a discount is an authority question, not a typo question. `uq_sfd_reverses` makes a
 * second reversal of the same row impossible, so "undo the undo" is a new discount with its own reason.
 *
 * **The abilities are on the module even though two of them can never pass**, because the module
 * matrix is uniform and a missing ability would read as an oversight. Answering "no, and here is why"
 * is better than a gate that suggests the right permission could unlock it.
 */
final class StudentFeeDiscountPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'fee_discounts';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentFeeDiscount $discount): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($discount));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Never. A wrong discount is reversed, not edited — see the class docblock.
     */
    public function update(User $user, StudentFeeDiscount $discount): bool
    {
        return false;
    }

    /**
     * Never. `trg_sfd_no_delete` backs this at the database, and the model throws first so the
     * refusal arrives with an explanation rather than as SQLSTATE 45000.
     */
    public function delete(User $user, StudentFeeDiscount $discount): bool
    {
        return false;
    }

    /** Nothing is ever soft-deleted here, so there is nothing to bring back. */
    public function restore(User $user, StudentFeeDiscount $discount): bool
    {
        return false;
    }

    /**
     * Approving a discount, and reversing one.
     *
     * [D18-8] adds the rule a policy cannot see: when `institute.discount_approval_required` is on,
     * the named approver must hold this ability, and may not be the creator unless the creator holds
     * it too. That is the service's check, because it depends on who *else* is on the form.
     */
    public function approve(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Approve);
    }

    public function reject(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Reject);
    }

    public function changeStatus(User $user, StudentFeeDiscount $discount): bool
    {
        return $this->approve($user) && $this->sharesBranch($user, $this->branchOf($discount));
    }

    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    private function branchOf(StudentFeeDiscount $discount): ?int
    {
        $branch = $discount->fee?->branch_id;

        return $branch === null ? null : (int) $branch;
    }
}
