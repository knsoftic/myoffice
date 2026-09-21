<?php

declare(strict_types=1);

namespace App\Policies\Finance;

use App\Enums\Ability;
use App\Enums\ExpenseStatus;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Policies\Finance\Concerns\ChecksFinancePermissions;

/**
 * Who may do what to an expense (phase-13 §4.5, §7.2).
 *
 * **Three acts, three permissions, because they are genuinely three different jobs.** `create` is
 * anybody claiming a cost, `approve` is somebody agreeing it is the company's, and `change_status` is
 * voiding one that has already been agreed. A single `edit` would have collapsed all three into one
 * permission nobody could hand out safely.
 *
 * **Nobody approves their own claim** unless `finance.expense_self_approval_allowed` says otherwise.
 * The point of an approval step is that a second person looked; a self-approval is a step that happened
 * on paper only. The service enforces it too — this is what stops the button rendering, not what stops
 * the act.
 *
 * **A derived row is not edited here.** A payroll expense is corrected by correcting the payroll run;
 * editing the shadow would leave the two disagreeing with nothing to say which is right.
 */
final class ExpensePolicy
{
    use ChecksFinancePermissions;

    public const MODULE = 'expenses';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($expense)
            && ! $expense->isDerived()
            && $expense->status !== ExpenseStatus::Voided;
    }

    public function delete(User $user, Expense $expense): bool
    {
        // Only while nobody has decided about it. Once somebody has, the decision is part of the
        // record and the act is a void with a reason.
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $expense->isDerived()
            && $expense->status->isDeletable();
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function approve(User $user, Expense $expense): bool
    {
        if (! $this->holds($user, self::MODULE, Ability::Approve) || $expense->status !== ExpenseStatus::Pending) {
            return false;
        }

        return ! $this->isOwn($user, $expense)
            || (bool) setting('finance.expense_self_approval_allowed', false);
    }

    public function reject(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::Reject)
            && $expense->status === ExpenseStatus::Pending;
    }

    public function void(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $expense->status !== ExpenseStatus::Voided;
    }

    /**
     * Only an approved expense can have money come back from it: nothing was ever paid out on a claim
     * the business never agreed to.
     */
    public function refund(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $expense->status === ExpenseStatus::Approved
            && ! $expense->isFullyRefunded();
    }

    public function download(User $user, Expense $expense): bool
    {
        return $this->holds($user, self::MODULE, Ability::Download);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export)
            && $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    private function isOwn(User $user, Expense $expense): bool
    {
        return $expense->created_by !== null && (int) $expense->created_by === (int) $user->getKey();
    }
}
