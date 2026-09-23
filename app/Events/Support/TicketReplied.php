<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\SupportTicket;
use App\Models\Support\TicketReply;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody replied to a ticket (§6.16 `reply()`, §10.3 `ticket.replied`).
 *
 * **The reply travels, not just the ticket**, because who hears about it depends entirely on which
 * reply it was: a requester's reply reaches staff, a public staff reply reaches the requester, and
 * an internal note reaches staff only. A listener holding just the ticket would have to guess, and
 * guessing wrong here means showing a client an internal note's existence.
 */
final class TicketReplied implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly TicketReply $reply,
        public readonly ?int $actorId = null,
    ) {}
}
