<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a fee was reduced (`student_fee_discounts.discount_type`, finance spine §3).
 *
 * The table is append-only (D16, D19): a discount granted in error is undone by a `reversal` row that
 * references the original, never by editing or deleting it. `correction` is for an amount that was
 * simply typed wrong; `reversal` is for one that should not have been granted at all, and the two read
 * differently in a dispute for exactly that reason.
 */
enum FeeDiscountType: string
{
    use HasOptions;

    case FixedDiscount = 'fixed_discount';
    case PercentageDiscount = 'percentage_discount';
    case Scholarship = 'scholarship';
    case PromotionalDiscount = 'promotional_discount';
    case ReferralDiscount = 'referral_discount';
    case Waiver = 'waiver';
    case Correction = 'correction';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::FixedDiscount => 'Fixed discount',
            self::PercentageDiscount => 'Percentage discount',
            self::Scholarship => 'Scholarship',
            self::PromotionalDiscount => 'Promotional discount',
            self::ReferralDiscount => 'Referral discount',
            self::Waiver => 'Waiver',
            self::Correction => 'Correction',
            self::Reversal => 'Reversal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scholarship => 'violet',
            self::FixedDiscount, self::PercentageDiscount, self::PromotionalDiscount => 'sky',
            self::ReferralDiscount => 'emerald',
            self::Waiver => 'amber',
            self::Correction => 'slate',
            self::Reversal => 'rose',
        };
    }

    /**
     * Is this an award to the student rather than a price adjustment? Scholarships are reported and
     * budgeted separately, which is the only reason the distinction is a case and not a note.
     */
    public function isScholarship(): bool
    {
        return $this === self::Scholarship;
    }

    /**
     * Does this row undo another one?
     */
    public function isUndo(): bool
    {
        return in_array($this, [self::Correction, self::Reversal], true);
    }

    public function requiresPercentage(): bool
    {
        return $this === self::PercentageDiscount;
    }
}
