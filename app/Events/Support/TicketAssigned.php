<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\SupportTicket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket changed hands (§6.16 `assign()`, §10.3 `ticket.assigned`).
 *
 * **The previous assignee travels too.** Handing a ticket on is not the same act as picking up an
 * unassigned one, and the person it left should be able to tell the difference between "somebody
 * took this from me" and "this was never mine".
 */
final class TicketAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly ?int $previousAssigneeId = null,
        public readonly bool $automatic = false,
        public readonly ?int $actorId = null,
    ) {}
}
