<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a support ticket has got to (phase-19-23 §3.4, §2.28.7, requirement §93).
 *
 * **§93's five, verbatim.** No sixth case has been invented, and the two that look like candidates
 * were considered and refused: "reopened" is not a state — it is `open` again, with `reopened_count`
 * recording that it has been here before — and "escalated" is a change of assignee and priority, not
 * a change of what is happening to the ticket.
 *
 * **`waiting` is the only case that pauses the SLA, and that is the whole reason it exists.** A
 * ticket sitting on the requester's desk is not the institute being slow; counting that time against
 * a resolution target would make every SLA report a measure of how quickly customers answer email.
 * `pausesSla()` is what `TicketSlaService` reads, and `waiting_since` / `total_waiting_minutes` on
 * the row are what make the pause auditable rather than merely asserted.
 *
 * **`requesterCanReply()` is not the same question as `isOpen()`.** A resolved ticket still accepts a
 * reply — that is precisely how somebody says "this is not fixed", and §2.28.7 turns such a reply
 * back into `open` inside the reopen window. Only `closed` refuses one, and then the answer is a new
 * ticket rather than a silent refusal.
 */
enum TicketStatus: string
{
    use HasOptions;

    /** Raised, nobody has picked it up. */
    case Open = 'open';

    /** An agent is working on it. */
    case InProgress = 'in_progress';

    /** Waiting on the requester. The SLA clock is stopped. */
    case Waiting = 'waiting';

    /** An answer has been given. The requester can still disagree. */
    case Resolved = 'resolved';

    /** Finished. A reply here starts a new ticket instead. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Waiting => 'Waiting on the requester',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'rose',
            self::InProgress => 'amber',
            self::Waiting => 'slate',
            self::Resolved => 'emerald',
            self::Closed => 'slate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Raised and not yet picked up.',
            self::InProgress => 'Somebody is working on it.',
            self::Waiting => 'We are waiting on the person who raised it. The response clock is paused.',
            self::Resolved => 'Answered. They can reply if it is not fixed.',
            self::Closed => 'Finished. A reply now raises a new ticket.',
        };
    }

    /** Still on somebody's queue. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Open, self::InProgress, self::Waiting => true,
            self::Resolved, self::Closed => false,
        };
    }

    /**
     * Does the SLA clock stop here?
     *
     * Only `waiting`. `resolved` does not pause it — it *stops* it, because the target has been met
     * or missed by then and there is nothing left to measure.
     */
    public function pausesSla(): bool
    {
        return $this === self::Waiting;
    }

    public function isResolvedOrClosed(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    /** Nothing follows this but a new ticket. */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * May the person who raised it add a reply?
     *
     * Everything but `closed`. A reply to a resolved ticket is how somebody says it is not fixed, and
     * §2.28.7 reopens it for them rather than making them argue with a form.
     */
    public function requesterCanReply(): bool
    {
        return $this !== self::Closed;
    }
}
