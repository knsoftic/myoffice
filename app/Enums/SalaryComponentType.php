<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which side of a slip a component falls on (phase-07 §2.18, §3, requirement §28).
 */
enum SalaryComponentType: string
{
    use HasOptions;

    case Earning = 'earning';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Earning => 'emerald',
            self::Deduction => 'rose',
        };
    }


    /**
     * +1 for an earning, -1 for a deduction.
     */
    public function sign(): int
    {
        return $this === self::Earning ? 1 : -1;
    }
}
