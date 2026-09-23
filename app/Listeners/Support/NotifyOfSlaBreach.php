<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Events\Support\TicketSlaBreached;
use App\Services\Support\NotificationService;

/**
 * A missed target reaches the assignee **and** whoever is accountable for it (§10.3
 * `ticket.sla_breach`).
 *
 * **Both, not either.** The assignee needs to act; the holder of `support_tickets.view_reports`
 * needs to know the desk is behind. An unassigned ticket that breached has nobody to act, which is
 * exactly when the second audience matters most — so the permission audience is always included
 * rather than used as a fallback.
 *
 * **This event is `mandatory` in the registry**, so it reaches people who have muted everything
 * else. A target that can be missed quietly is not a target.
 */
final class NotifyOfSlaBreach
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketSlaBreached $event): void
    {
        $ticket = $event->ticket;
        $assignee = $ticket->getAttribute('assigned_to');

        $audience = AudienceInput::permission('support_tickets.view_reports');

        if ($assignee !== null) {
            $audience = $audience->plus((int) $assignee);
        }

        $this->notifications->dispatch('ticket.sla_breach', $audience, [
            'title' => sprintf(
                'Ticket %s missed its %s target',
                $ticket->getAttribute('ticket_number'),
                $event->label(),
            ),
            'body' => $assignee === null
                ? 'Nobody has picked this ticket up, and it is now past its target.'
                : (string) $ticket->getAttribute('subject'),
            'url' => $this->ticketUrl($ticket),
            'ticket_id' => (int) $ticket->getKey(),
            'kind' => $event->kind,
            'unassigned' => $assignee === null,
        ]);
    }
}
