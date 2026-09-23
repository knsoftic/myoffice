<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What an invitee said (phase-19-23 §3.4, §2.21, requirement §95).
 *
 * **`pending` is a real answer, not a missing one.** It is the difference between "has not replied"
 * and "cannot come", and an organiser needs to tell them apart before deciding whether to move a
 * meeting. That is also why `isAnswered()` exists rather than a `!== Pending` written out at every
 * call site: the accepted / declined counts and the reminder sweep both turn on the same question.
 *
 * **A material change to the meeting resets everybody to `pending`** (`MeetingService::update()`).
 * An invitation accepted for Tuesday is not an acceptance for Thursday, and carrying the old answer
 * forward would show an organiser a quorum that nobody has agreed to.
 */
enum MeetingResponse: string
{
    use HasOptions;

    /** Invited, has not said. */
    case Pending = 'pending';

    /** Coming. */
    case Accepted = 'accepted';

    /** Not coming. */
    case Declined = 'declined';

    /** Will come if they can. */
    case Tentative = 'tentative';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'No reply yet',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Tentative => 'Tentative',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Accepted => 'emerald',
            self::Declined => 'rose',
            self::Tentative => 'amber',
        };
    }

    /**
     * Have they said anything at all?
     *
     * `tentative` counts as answered: the person has engaged with the invitation, and chasing them
     * again is how a reminder becomes noise people learn to ignore.
     */
    public function isAnswered(): bool
    {
        return $this !== self::Pending;
    }
}
