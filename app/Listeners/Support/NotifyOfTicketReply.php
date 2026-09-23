<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Enums\ReplyVisibility;
use App\Events\Support\TicketReplied;
use App\Services\Support\NotificationService;

/**
 * A reply reaches the other side, and only the other side (§10.3 `ticket.replied`).
 *
 * Three cases, and getting any of them wrong is worse than sending nothing:
 *
 *   - an **internal note** reaches staff and never the requester. The whole point of the
 *     distinction is that the requester does not know it exists, so a notification about one would
 *     undo the feature in a single line;
 *   - a **requester's reply** reaches the assignee, or the desk when there is none;
 *   - a **public staff reply** reaches the requester.
 *
 * **Nobody is told about their own reply.** A notification about something you just wrote reads as
 * a bug, so the author is excluded by name rather than left to be filtered out later.
 */
final class NotifyOfTicketReply
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketReplied $event): void
    {
        $ticket = $event->ticket;
        $reply = $event->reply;

        $authorId = (int) $reply->getAttribute('user_id');
        $requesterId = (int) $ticket->getAttribute('user_id');
        $assignee = $ticket->getAttribute('assigned_to');

        $internal = $reply->visibility === ReplyVisibility::InternalNote;
        $byRequester = $authorId === $requesterId;

        if ($internal || $byRequester) {
            // Staff's turn. The assignee unless they wrote it; otherwise whoever may pick it up.
            $audience = $assignee !== null && (int) $assignee !== $authorId
                ? AudienceInput::of((int) $assignee)
                : AudienceInput::permission('support_tickets.assign');
        } else {
            $audience = AudienceInput::of($requesterId);
        }

        $this->notifications->dispatch('ticket.replied', $audience, [
            'title' => sprintf('Ticket %s has a reply', $ticket->getAttribute('ticket_number')),
            'body' => $internal
                ? 'An internal note was added to this ticket.'
                : 'There is a new reply on this ticket.',
            'url' => $this->ticketUrl($ticket),
            'ticket_id' => (int) $ticket->getKey(),
            'reply_id' => (int) $reply->getKey(),
            'internal' => $internal,
        ]);
    }
}
