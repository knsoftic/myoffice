<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why somebody is in the room (phase-19-23 §3.4, §2.21, requirement §95).
 *
 * **`countsInQuorum()` is the only member that decides anything**, and it is what keeps "three of
 * five accepted" from being a meaningless fraction. An optional attendee declining is not a meeting
 * in trouble; a required one declining is. A note taker is required to be *there* but is not a
 * participant in the decision, which is why they are their own case rather than a flag on `required`
 * — and why they still count: a meeting whose minute taker has dropped out is worth noticing.
 *
 * There is exactly one `organizer` per meeting, inserted by `MeetingService::create()` as accepted,
 * because somebody who called a meeting has by definition agreed to attend it.
 */
enum MeetingParticipantRole: string
{
    use HasOptions;

    /** Called the meeting. Inserted as accepted. */
    case Organizer = 'organizer';

    /** The meeting does not work without them. */
    case Required = 'required';

    /** Welcome, and their absence changes nothing. */
    case Optional = 'optional';

    /** There to write the minutes. */
    case NoteTaker = 'note_taker';

    public function label(): string
    {
        return match ($this) {
            self::Organizer => 'Organiser',
            self::Required => 'Required',
            self::Optional => 'Optional',
            self::NoteTaker => 'Note taker',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Organizer => 'brand',
            self::Required => 'sky',
            self::Optional => 'slate',
            self::NoteTaker => 'indigo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Organizer => 'Called the meeting and owns its notes and its outcome.',
            self::Required => 'The meeting does not go ahead properly without them.',
            self::Optional => 'Invited for information. Their absence does not hold anything up.',
            self::NoteTaker => 'There to record what was decided.',
        };
    }

    /**
     * Does this person count towards "enough people accepted"?
     *
     * An optional attendee declining is not a meeting in trouble. A note taker declining is — a
     * meeting nobody is minuting is a meeting whose decisions will be remembered differently by
     * everybody in it.
     */
    public function countsInQuorum(): bool
    {
        return $this !== self::Optional;
    }
}
