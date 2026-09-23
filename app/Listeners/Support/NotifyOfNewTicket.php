<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Events\Support\TicketCreated;
use App\Services\Support\NotificationService;

/**
 * A new ticket reaches whoever has to pick it up (§10.3 `ticket.created`).
 *
 * **The assignee if there is one, the holders of `support_tickets.assign` if there is not.** A
 * ticket auto-assigned on creation has an owner already, and paging the whole desk about it would
 * teach everybody to ignore the notification. One with nobody is on the queue, and a queue is only
 * watched if somebody is told.
 *
 * **The requester is not told.** They just pressed the button.
 */
final class NotifyOfNewTicket
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket;
        $assignee = $ticket->getAttribute('assigned_to');

        $audience = $assignee !== null
            ? AudienceInput::of((int) $assignee)
            : AudienceInput::permission('support_tickets.assign');

        $this->notifications->dispatch('ticket.created', $audience, [
            'title' => sprintf('Ticket %s: %s', $ticket->getAttribute('ticket_number'), $ticket->getAttribute('subject')),
            'body' => $assignee !== null
                ? 'A ticket has been raised and assigned to you.'
                : 'A ticket has been raised and nobody has picked it up yet.',
            'url' => $this->ticketUrl($ticket),
            'ticket_id' => (int) $ticket->getKey(),
            'ticket_number' => $ticket->getAttribute('ticket_number'),
            'priority' => $ticket->getRawOriginal('priority'),
        ]);
    }
}
