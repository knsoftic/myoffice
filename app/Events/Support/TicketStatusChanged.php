<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Enums\TicketStatus;
use App\Models\Support\SupportTicket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket moved along §2.28.7's path (§6.16 `changeStatus()`, §10.3 `ticket.status_changed`).
 *
 * **Both ends of the move travel**, because the requester is told about three of them and not the
 * rest: resolved, closed and reopened are things that happened *to their request*, while
 * `open → in_progress` is a desk managing its own queue. A listener holding only the new status
 * cannot tell a reopen from a first open, and a reopen is the one the requester most wants to hear.
 */
final class TicketStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
