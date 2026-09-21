<?php

declare(strict_types=1);

namespace App\Policies\Finance;

use App\Enums\Ability;
use App\Models\Finance\FinanceCategory;
use App\Models\User;
use App\Policies\Finance\Concerns\ChecksFinancePermissions;

/**
 * Who may maintain the expense and income categories (phase-13 §2.3, D44).
 *
 * **`salaries` cannot be deleted or switched off.** Every paid payroll run posts into it, and a missing
 * category would not fail loudly — it would drop the largest line out of the profit-and-loss statement
 * and leave the statement looking plausible. That is why deactivation is refused as well as deletion: a
 * category hidden from the dropdown is just as missing to the job that needs it.
 *
 * **A category carrying money history is never hard-deleted.** Deactivation hides it from the forms and
 * leaves every report intact; deletion would orphan the rows filed under it.
 */
final class FinanceCategoryPolicy
{
    use ChecksFinancePermissions;

    public const MODULE = 'finance_categories';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, FinanceCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, FinanceCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit) && ! $this->isTrashed($category);
    }

    public function delete(User $user, FinanceCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $category->isReserved()
            && ! $category->isInUse();
    }

    public function restore(User $user, FinanceCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    /**
     * Switching one **on** is always allowed; switching the reserved one **off** is not.
     */
    public function changeStatus(User $user, FinanceCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ($category->isDeactivatable() || ! $category->is_active);
    }
}
