<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Events\Support\TicketAssigned;
use App\Services\Support\NotificationService;

/**
 * The new assignee is told a ticket is theirs (§10.3 `ticket.assigned`).
 *
 * **Only the new one.** The person it left sees it leave their queue, which is the same fact
 * delivered by the screen they were already looking at; a notification saying "this is no longer
 * yours" interrupts somebody to ask nothing of them.
 *
 * **Somebody picking a ticket up themselves is not announced to themselves.** That is the commonest
 * act on a support desk, and notifying it would produce noise at exactly the rate the desk is busy.
 * An *automatic* assignment is always announced, because nobody chose it.
 */
final class NotifyOfTicketAssignment
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketAssigned $event): void
    {
        $ticket = $event->ticket;
        $assignee = $ticket->getAttribute('assigned_to');

        if ($assignee === null) {
            return;
        }

        if (! $event->automatic && $event->actorId !== null && (int) $assignee === $event->actorId) {
            return;
        }

        $this->notifications->dispatch('ticket.assigned', AudienceInput::of((int) $assignee), [
            'title' => sprintf('Ticket %s is yours', $ticket->getAttribute('ticket_number')),
            'body' => (string) $ticket->getAttribute('subject'),
            'url' => $this->ticketUrl($ticket),
            'ticket_id' => (int) $ticket->getKey(),
            'automatic' => $event->automatic,
        ]);
    }
}
