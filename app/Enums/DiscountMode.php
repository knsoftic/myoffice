<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a discount is expressed, on an invoice or on one of its lines (phase-13 §2.4, §2.5).
 *
 * The two accessor methods are what `chk_inv_discount_payload` and `chk_ii_discount_payload` enforce in
 * the database: a percentage discount carries a rate and no fixed amount, a fixed one carries an amount
 * and no rate, and `none` carries neither. A row that disagrees with its own mode cannot be saved,
 * which is what stops "10% off" quietly meaning PKR 10.
 */
enum DiscountMode: string
{
    use HasOptions;

    case None = 'none';
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No discount',
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'slate',
            self::Percentage => 'sky',
            self::Fixed => 'violet',
        };
    }

    public function requiresRate(): bool
    {
        return $this === self::Percentage;
    }

    public function requiresAmount(): bool
    {
        return $this === self::Fixed;
    }
}
