<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a leave type hands out its quota (phase-07 §2.12, §3, requirement §27).
 *
 * `annual_grant` credits the whole year at once; `monthly_accrual` credits a twelfth each month, which is
 * what makes a mid-year joiner's entitlement honest without a special case. `none` is for types that are
 * granted case by case — bereavement, unpaid — where a balance would be a fiction.
 */
enum LeaveAccrualMethod: string
{
    use HasOptions;

    case AnnualGrant = 'annual_grant';
    case MonthlyAccrual = 'monthly_accrual';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::AnnualGrant => 'Annual grant',
            self::MonthlyAccrual => 'Monthly accrual',
            self::None => 'No quota',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AnnualGrant => 'emerald',
            self::MonthlyAccrual => 'sky',
            self::None => 'slate',
        };
    }

    /**
     * Does this method credit a balance at all?
     */
    public function credits(): bool
    {
        return $this !== self::None;
    }
}
