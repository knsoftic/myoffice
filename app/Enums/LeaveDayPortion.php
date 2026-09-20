<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How much of one day a leave day takes (phase-07 §2.16, §3).
 *
 * {@see fraction()} is a **decimal string**, never a float: it is multiplied into a quota and into a
 * payable factor, and both of those are money-adjacent (HR-12).
 */
enum LeaveDayPortion: string
{
    use HasOptions;

    case FullDay = 'full_day';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    public function label(): string
    {
        return match ($this) {
            self::FullDay => 'Full day',
            self::FirstHalf => 'First half',
            self::SecondHalf => 'Second half',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FullDay => 'sky',
            self::FirstHalf => 'amber',
            self::SecondHalf => 'orange',
        };
    }

    /**
     * The share of a day this portion consumes, as a decimal string.
     */
    public function fraction(): string
    {
        return $this === self::FullDay ? '1.0000' : '0.5000';
    }

    public function isHalf(): bool
    {
        return $this !== self::FullDay;
    }
}
