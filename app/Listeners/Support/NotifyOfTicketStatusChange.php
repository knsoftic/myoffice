<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Enums\TicketStatus;
use App\Events\Support\TicketStatusChanged;
use App\Services\Support\NotificationService;

/**
 * The requester hears about the three moves that concern them (§10.3 `ticket.status_changed`).
 *
 * **Resolved, closed and reopened, and nothing else.** `open → in_progress → waiting` is a desk
 * managing its own queue; telling a client each time a ticket moved between those would be a
 * running commentary on somebody else's workflow, and the three below would be lost inside it.
 * These three are the ones that change what the requester should do next.
 *
 * **A first `open` is not a reopen.** A ticket's first open state is its creation, which
 * `ticket.created` has already announced — so `open` only counts as news when it arrives from
 * `resolved` or `closed`.
 *
 * **Somebody who made the change is not told about it**, which in practice is the requester
 * reopening their own ticket.
 */
final class NotifyOfTicketStatusChange
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketStatusChanged $event): void
    {
        $reopened = $event->to === TicketStatus::Open;

        if (! $reopened && ! $event->to->isResolvedOrClosed()) {
            return;
        }

        if ($reopened && ! $event->from->isResolvedOrClosed()) {
            return;
        }

        $ticket = $event->ticket;
        $requesterId = (int) $ticket->getAttribute('user_id');

        if ($event->actorId !== null && $event->actorId === $requesterId) {
            return;
        }

        $this->notifications->dispatch('ticket.status_changed', AudienceInput::of($requesterId), [
            'title' => sprintf(
                'Ticket %s is %s',
                $ticket->getAttribute('ticket_number'),
                $reopened ? 'open again' : mb_strtolower($event->to->label()),
            ),
            'body' => $event->reason ?? (string) $ticket->getAttribute('subject'),
            'url' => $this->ticketUrl($ticket),
            'ticket_id' => (int) $ticket->getKey(),
            'from' => $event->from->value,
            'to' => $event->to->value,
        ]);
    }
}
