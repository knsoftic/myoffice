<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where one dated class stands (`class_sessions.status`, phase-14-17 §2.30.9).
 *
 * **`held` is the only status attendance counts.** A cancelled class is not an absence for anybody,
 * and a rescheduled one happened somewhere else — counting either would produce an attendance
 * percentage that punishes students for the institute's own decisions.
 *
 * **`rescheduled` and `cancelled` are different facts.** A rescheduled class has a successor row and
 * the two are linked; a cancelled one simply did not happen. Collapsing them would lose the answer to
 * "when was this actually taught".
 */
enum ClassSessionStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Held = 'held';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Held => 'Held',
            self::Cancelled => 'Cancelled',
            self::Rescheduled => 'Rescheduled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::Held => 'emerald',
            self::Cancelled => 'rose',
            self::Rescheduled => 'amber',
        };
    }

    /**
     * Does this class go into anybody's attendance denominator? Only one that actually happened.
     */
    public function countsInAttendance(): bool
    {
        return $this === self::Held;
    }

    /**
     * Does it still occupy the teacher's and the room's slot? `active_guard` mirrors this, so the
     * unique indexes and this method cannot disagree about whether a slot is free.
     */
    public function holdsASlot(): bool
    {
        return $this === self::Scheduled || $this === self::Held;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Rescheduled;
    }
}
