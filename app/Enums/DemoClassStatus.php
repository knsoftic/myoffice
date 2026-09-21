<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a booked demo stands (`demo_classes.status`, §87 plus one).
 *
 * **[D-IN-11] §87 lists four; `cancelled` is the fifth and it is not optional.** A demo the institute
 * calls off is neither `missed` — which says the attendee did not turn up, and blames them for a
 * decision that was not theirs — nor a deleted row, because the slot it held and the reason it was
 * dropped are what the teacher's week and the §88 report are made of.
 */
enum DemoClassStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Attended = 'attended';
    case Missed = 'missed';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Attended => 'Attended',
            self::Missed => 'Missed',
            self::Converted => 'Converted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::Attended => 'indigo',
            self::Missed => 'amber',
            self::Converted => 'emerald',
            self::Cancelled => 'slate',
        };
    }

    /** Does it still hold a slot in the teacher's day and the room's day? `active_guard` mirrors this. */
    public function holdsASlot(): bool
    {
        return $this === self::Scheduled;
    }

    public function isTerminal(): bool
    {
        return $this === self::Converted || $this === self::Cancelled;
    }

    /** Did the attendee turn up? The §88 demo-to-admission rate divides by these. */
    public function wasAttended(): bool
    {
        return $this === self::Attended || $this === self::Converted;
    }
}
