<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\SupportTicket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket was raised (phase-19-23 §6.16 `create()`, §10.2, §10.3 `ticket.created`).
 *
 * Listeners: `SyncDepartmentTicketCounts` recounts the desk, `NotifyOfNewTicket` tells whoever must
 * pick it up. Both after commit, because a ticket that rolled back is not a ticket anybody should
 * be paged about.
 */
final class TicketCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly ?int $actorId = null,
    ) {}
}
