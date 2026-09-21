<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * When somebody wants to study (`preferred_timing` on enquiries, applications and admissions; §67, §70).
 *
 * A select, not free text, because §70 matches a student to a batch by it and a report counts it.
 * `flexible` is a real answer and is kept distinct from "not stated" (a null), which is the
 * difference between a student who can take any slot and one nobody asked.
 */
enum PreferredTiming: string
{
    use HasOptions;

    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Night = 'night';
    case Weekend = 'weekend';
    case Flexible = 'flexible';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Morning',
            self::Afternoon => 'Afternoon',
            self::Evening => 'Evening',
            self::Night => 'Night',
            self::Weekend => 'Weekend',
            self::Flexible => 'Flexible',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Morning => 'amber',
            self::Afternoon => 'orange',
            self::Evening => 'indigo',
            self::Night => 'slate',
            self::Weekend => 'violet',
            self::Flexible => 'emerald',
        };
    }

    /** A student who will take anything matches every batch, so the picker stops filtering. */
    public function matchesAnything(): bool
    {
        return $this === self::Flexible;
    }
}
