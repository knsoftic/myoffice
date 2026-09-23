<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\SupportTicket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket passed a target it was given (§6.16 `TicketSlaService::sweep()`, §10.3
 * `ticket.sla_breach`).
 *
 * **`$kind` is `first_response` or `resolution`**, and they are different failures. Missing a first
 * response means nobody has looked at it; missing a resolution means somebody has and it is still
 * not done. Telling the desk which one it is decides what they do next, so one event carrying the
 * kind beats two events or a message that says "a target was missed".
 *
 * **This fires at most twice in a ticket's life, once per kind.** The sweep runs every ten minutes
 * and the breach booleans are what stop it paging the assignee 144 times a day about the same
 * ticket — they are stamped inside the same transaction that selects the row, and the next sweep
 * selects nothing.
 */
final class TicketSlaBreached implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        /** `first_response` or `resolution`. */
        public readonly string $kind,
    ) {}

    public function isFirstResponse(): bool
    {
        return $this->kind === 'first_response';
    }

    /** What the notification calls it. */
    public function label(): string
    {
        return $this->isFirstResponse() ? 'first reply' : 'resolution';
    }
}
