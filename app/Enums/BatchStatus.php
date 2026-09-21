<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a batch stands (`batches.status`, requirement §70, phase-14-17 §2.30.7).
 *
 * **`planned` and `enrolling` are not the same thing, and the difference is a promise.** A planned
 * batch is an intention — it may have no teacher and no timetable yet. Opening it for admission says
 * the institute will run it, which is why the move to `enrolling` requires a teacher *and* a
 * timetable entry: taking somebody's money for a class with no date is the failure that rule exists
 * to prevent.
 *
 * **`on_hold` keeps the roster and cancels the sessions.** A paused batch is one the students are
 * still enrolled in; cancelling it would empty the register and lose the history of who was in it.
 */
enum BatchStatus: string
{
    use HasOptions;

    case Planned = 'planned';
    case Enrolling = 'enrolling';
    case Running = 'running';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Enrolling => 'Enrolling',
            self::Running => 'Running',
            self::OnHold => 'On hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'slate',
            self::Enrolling => 'sky',
            self::Running => 'emerald',
            self::OnHold => 'amber',
            self::Completed => 'teal',
            self::Cancelled => 'rose',
        };
    }

    /**
     * May a student be seated in it? Only before it starts — §70's "current students" is a count of
     * people who will be in the room, and adding somebody to a running batch is a transfer decision.
     */
    public function acceptsEnrollment(): bool
    {
        return $this === self::Planned || $this === self::Enrolling;
    }

    /** Is it still a batch the institute is running? */
    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** Should sessions be generated for it? A paused or finished batch produces no new classes. */
    public function generatesSessions(): bool
    {
        return $this === self::Enrolling || $this === self::Running || $this === self::Planned;
    }

    /** Is it on the public "upcoming batches" strip (§89)? */
    public function isPubliclyOpen(): bool
    {
        return $this === self::Enrolling;
    }
}
