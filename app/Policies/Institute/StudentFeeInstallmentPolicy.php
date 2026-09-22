<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Enums\InstallmentStatus;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may schedule a fee, and who may waive part of one (phase-18 §9, §6.3).
 *
 * **Waiving takes two abilities, and that is the point of it being separate.** `installments.change_status`
 * says you may move this line; `fee_discounts.approve` says you may reduce what the student owes. A
 * waiver does both at once — it parks an amount in `waived_amount` **and** writes a discount row that
 * lowers the net fee — so requiring only the first would let somebody with no discount authority give
 * money away one installment at a time.
 *
 * **A line holding money is never deleted or re-amounted.** `chk_sfi_amount` and the spine's own rule
 * that a receipt points at the line it was paid against make that structural; this is where it is said
 * in terms a 403 can explain. Rebuilding a plan cancels the unpaid lines and leaves the paid ones
 * exactly where they are, keeping their numbers ([D18-6], D50).
 */
final class StudentFeeInstallmentPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'installments';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentFeeInstallment $line): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($line));
    }

    /** Building the first plan. [D18-4]'s "not after money has arrived" is the service's rule. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Rebuilding a plan, or re-amounting one unpaid line.
     *
     * A line that holds money is refused here rather than deeper down, because the person who clicked
     * Edit on it needs to be told why the amount is fixed, not handed a constraint violation.
     */
    public function update(User $user, StudentFeeInstallment $line): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($line)
            && ! $line->holdsMoney()
            && $line->status !== InstallmentStatus::Cancelled
            && $this->sharesBranch($user, $this->branchOf($line));
    }

    /**
     * Waiving, and cancelling a line.
     *
     * **Both abilities, deliberately** (§6.1, spine §2.18.2). The service asks for a reason on top.
     */
    public function changeStatus(User $user, StudentFeeInstallment $line): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $user->can(StudentFeeDiscountPolicy::MODULE.'.'.Ability::Approve->value)
            && ! $this->isTrashed($line)
            && $line->status !== InstallmentStatus::Cancelled
            && $this->sharesBranch($user, $this->branchOf($line));
    }

    /**
     * A line that has never received anything, on a plan being rebuilt wholesale. Everything else is
     * cancelled rather than deleted, so the schedule still reads in order with its gaps explained.
     */
    public function delete(User $user, StudentFeeInstallment $line): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($line)
            && ! $line->holdsMoney()
            && $this->sharesBranch($user, $this->branchOf($line));
    }

    public function restore(User $user, StudentFeeInstallment $line): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($line);
    }

    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function print(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /** A line carries no branch of its own; it inherits the charge's (D11). */
    private function branchOf(StudentFeeInstallment $line): ?int
    {
        $branch = $line->fee?->branch_id;

        return $branch === null ? null : (int) $branch;
    }
}
