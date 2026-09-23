<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Who can read a ticket reply (phase-19-23 §3.4, §6.16, §9.4, requirement §93).
 *
 * **Two cases, and the second one is the dangerous one.** An `internal_note` is written on the
 * assumption that the requester will never see it — agents use it to say "this client has been
 * warned twice" or "waive it, they are a big account" — so every query on a portal panel filters to
 * `public`, `reachesRequester()` is the one question every one of those filters asks, and a portal
 * user's own reply is **forced** to `public` rather than validated: a field a requester controls
 * must not be able to mint a note only staff were meant to see, and validating it would leave the
 * shape of the mistake in the request.
 *
 * Writing one requires `support_tickets.edit`, which is the ability an agent has and a requester
 * never does — so the permission and the forced value agree, and either alone would be enough.
 */
enum ReplyVisibility: string
{
    use HasOptions;

    /** The requester reads this. */
    case Public = 'public';

    /** Staff only. The requester never sees it, on any panel, in any export. */
    case InternalNote = 'internal_note';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Reply to the requester',
            self::InternalNote => 'Internal note',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Public => 'sky',
            self::InternalNote => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Sent to the person who raised the ticket and shown on their panel.',
            self::InternalNote => 'Visible to staff only. It is never shown on a portal, never emailed to the requester and never in an export they can reach.',
        };
    }

    /**
     * Does this reply reach the person who raised the ticket?
     *
     * The one question every portal query, every notification and every export asks. A second way of
     * asking it is a second place to get it wrong.
     */
    public function reachesRequester(): bool
    {
        return $this === self::Public;
    }
}
