<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where an "other income" row stands (`incomes.status`, requirement §29, phase-13 §2.6).
 *
 * Two cases, because income needs no approval: the money either arrived or it did not. Voiding is the
 * only undoing, and like every other undoing in this system it appends rather than deletes.
 */
enum IncomeStatus: string
{
    use HasOptions;

    case Recorded = 'recorded';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Voided => 'Voided',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Recorded => 'emerald',
            self::Voided => 'slate',
        };
    }

    public function countsInReports(): bool
    {
        return $this === self::Recorded;
    }
}
