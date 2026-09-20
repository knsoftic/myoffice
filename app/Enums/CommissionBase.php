<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What number the percentage is taken of (`collaborator_commission_settings.commission_base`,
 * finance spine §3).
 *
 * **`paid` is the default, and the safe one** (`CLAUDE.md` §5): commission on what actually arrived can
 * never exceed what the business collected. `gross` and `net_after_discount` pay on what was *promised*,
 * which is legitimate for a partner who is paid on the sale rather than on the collection — but it means
 * a student who pays half leaves the business having paid full commission, and that is a decision
 * somebody must make deliberately rather than inherit.
 *
 * `total_value` and `milestone` are the project side's equivalents and are meaningless on a student fee,
 * which is what `appliesTo()` exists to say.
 */
enum CommissionBase: string
{
    use HasOptions;

    case Gross = 'gross';
    case NetAfterDiscount = 'net_after_discount';
    case Paid = 'paid';
    case TotalValue = 'total_value';
    case Milestone = 'milestone';

    public function label(): string
    {
        return match ($this) {
            self::Gross => 'Gross fee',
            self::NetAfterDiscount => 'Net of discount',
            self::Paid => 'Amount actually paid',
            self::TotalValue => 'Total project value',
            self::Milestone => 'Milestone amount',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'emerald',
            self::NetAfterDiscount => 'sky',
            self::Gross, self::TotalValue => 'amber',
            self::Milestone => 'violet',
        };
    }

    /**
     * Is this base meaningful for that scope?
     */
    public function appliesTo(CommissionScope $scope): bool
    {
        return match ($this) {
            self::Gross, self::NetAfterDiscount, self::Paid => true,
            self::TotalValue, self::Milestone => $scope === CommissionScope::Project,
        };
    }

    /**
     * Does this base promise commission on money the business has not collected yet?
     *
     * The three that do are why an **entitlement** exists: the promise is made once and released as
     * receipts arrive, so a partner is never paid more than the business took in.
     */
    public function promisesBeforeCollection(): bool
    {
        return $this !== self::Paid;
    }
}
