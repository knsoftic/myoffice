<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\Models\Support\Meeting;
use App\Models\Support\SupportTicket;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The deep links the Phase 22 listeners put in a bell row.
 *
 * **A named route that does not exist yet must not take the notification down with it.** The
 * screens land after the services — that is the order every phase in this project has been built
 * in — so between now and then `route('admin.tickets.show')` throws `RouteNotFoundException`, and
 * an unhandled throw inside a listener fails the queued job and loses the message. The link is the
 * least important part of a notification; losing the notification to save the link is the wrong
 * trade.
 *
 * **The fallback is a literal path, not null.** It is the path those routes will have, so a row
 * written today keeps working the day the screen ships — and a row with no link at all is one
 * somebody has to go and find by hand.
 *
 * This is phase-04's `BuildsCmsNotification::link()` rule, applied on the listener side: the same
 * problem, the same answer, and the two are kept apart only because one lives on a notification and
 * one on a listener.
 */
trait BuildsTicketLinks
{
    protected function ticketUrl(SupportTicket $ticket): string
    {
        return $this->link('admin.tickets.show', ['ticket' => $ticket->getKey()], '/admin/tickets/'.$ticket->getKey());
    }

    protected function meetingUrl(Meeting $meeting): string
    {
        return $this->link('admin.meetings.show', ['meeting' => $meeting->getKey()], '/admin/meetings/'.$meeting->getKey());
    }

    protected function conversationUrl(int $conversationId): string
    {
        return $this->link('admin.messages.show', ['conversation' => $conversationId], '/admin/messages/'.$conversationId);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function link(string $name, array $parameters, string $fallback): string
    {
        try {
            if (Route::has($name)) {
                return route($name, $parameters, false);
            }
        } catch (Throwable) {
            // Fall through to the literal path.
        }

        return $fallback;
    }
}
