<?php

declare(strict_types=1);

namespace App\Policies\Finance;

use App\Enums\Ability;
use App\Models\Finance\PaymentMethodOption;
use App\Models\User;
use App\Policies\Finance\Concerns\ChecksFinancePermissions;

/**
 * Who may configure a payment method (phase-13 §2.2, §4.5).
 *
 * **Deletion is refused while anything references the row.** The correct act is deactivation, which
 * hides it from every dropdown and changes no history: a payment made last year by a method the
 * business has since stopped offering is still a payment made by that method, and a deleted row would
 * leave that receipt pointing at nothing.
 *
 * The module declares **no `view_financial`** — it holds no amount — and the encrypted gateway config
 * is reachable through `edit` alone, which is a permission to *replace* it, never to read it.
 */
final class PaymentMethodPolicy
{
    use ChecksFinancePermissions;

    public const MODULE = 'payment_methods';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, PaymentMethodOption $method): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, PaymentMethodOption $method): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit) && ! $this->isTrashed($method);
    }

    public function delete(User $user, PaymentMethodOption $method): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete) && ! $this->isInUse($method);
    }

    public function restore(User $user, PaymentMethodOption $method): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function changeStatus(User $user, PaymentMethodOption $method): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }

    private function isInUse(PaymentMethodOption $method): bool
    {
        return $method->projectPayments()->exists()
            || $method->studentFeePayments()->exists()
            || $method->expenses()->exists()
            || $method->incomes()->exists();
    }
}
