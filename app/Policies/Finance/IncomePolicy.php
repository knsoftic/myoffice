<?php

declare(strict_types=1);

namespace App\Policies\Finance;

use App\Enums\Ability;
use App\Enums\IncomeStatus;
use App\Models\Finance\Income;
use App\Models\User;
use App\Policies\Finance\Concerns\ChecksFinancePermissions;

/**
 * Who may do what to an "other income" row (phase-13 §4.5, §7.3).
 *
 * The same shape as {@see ExpensePolicy} without the approval workflow: there is nothing to agree about
 * money that has already arrived. What remains is the rule that matters — **a row with history against
 * it is voided, never deleted**, because a refunded or voided entry is part of what the business can
 * explain about its own bank balance.
 */
final class IncomePolicy
{
    use ChecksFinancePermissions;

    public const MODULE = 'income';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Income $income): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Income $income): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($income)
            && $income->status !== IncomeStatus::Voided;
    }

    public function delete(User $user, Income $income): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $income->status === IncomeStatus::Recorded
            && $income->reversals()->doesntExist();
    }

    public function restore(User $user, Income $income): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function void(User $user, Income $income): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $income->status !== IncomeStatus::Voided;
    }

    public function refund(User $user, Income $income): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $income->status === IncomeStatus::Recorded
            && ! $income->isFullyRefunded();
    }

    public function download(User $user, Income $income): bool
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
}
