<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What happened to a meeting (phase-19-23 §3.4, §2.28.8, requirement §95).
 *
 * **§95 names a "status"; these five are that status plus the two real-world endings it left out.**
 * `postponed` and `missed` are not embellishments: a meeting that slipped and a meeting nobody
 * attended are different facts, and collapsing either into `cancelled` would make the §99 meeting
 * report say the office cancels far more than it does. `postponed` also carries a successor —
 * `reschedule()` writes a new row with `rescheduled_from_id` — so a meeting that has slipped three
 * times reads as a chain rather than as three unrelated cancellations.
 *
 * **`countsInReports()` exists because a cancelled meeting is not a meeting that happened.** An
 * attendance rate that counted cancellations would fall every time somebody did the responsible
 * thing and called one off in advance.
 */
enum MeetingStatus: string
{
    use HasOptions;

    /** In the diary. */
    case Scheduled = 'scheduled';

    /** It happened. */
    case Completed = 'completed';

    /** Called off, with a reason. */
    case Cancelled = 'cancelled';

    /** Moved. A new row carries the new date and points back at this one. */
    case Postponed = 'postponed';

    /** The time passed and nobody recorded it. Written by the sweep, never by a person. */
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Postponed => 'Postponed',
            self::Missed => 'Missed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::Completed => 'emerald',
            self::Cancelled => 'rose',
            self::Postponed => 'amber',
            self::Missed => 'slate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Scheduled => 'In the diary. Participants can still accept or decline.',
            self::Completed => 'It took place, and attendance has been recorded.',
            self::Cancelled => 'Called off. The reason is shown to everybody who was invited.',
            self::Postponed => 'Moved to a new date. The replacement points back at this one.',
            self::Missed => 'The time passed with nothing recorded. Marked by the nightly sweep, not by a person.',
        };
    }

    /** Still in the diary — the only state that holds a room and accepts a response. */
    public function isLive(): bool
    {
        return $this === self::Scheduled;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Scheduled;
    }

    /**
     * Does this meeting count towards the §99 figures?
     *
     * A cancellation is the office doing the right thing in advance; counting it would make an
     * attendance rate punish good behaviour. A postponement is counted on its successor, not here,
     * or the same meeting would be counted twice.
     */
    public function countsInReports(): bool
    {
        return match ($this) {
            self::Completed, self::Missed => true,
            self::Scheduled, self::Cancelled, self::Postponed => false,
        };
    }
}
