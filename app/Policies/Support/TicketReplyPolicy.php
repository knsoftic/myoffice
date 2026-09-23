<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Enums\ReplyVisibility;
use App\Models\Support\TicketReply;
use App\Models\User;
use App\Policies\Support\Concerns\ChecksSupportPermissions;

/**
 * What may be done to a single reply (phase-19-23 §6.16, INV-22-1).
 *
 * **`view` is where the internal note lives or dies.** A requester who could read one would make
 * the whole distinction pointless — internal notes are where a desk says "this is the third time
 * this week" — so the visibility is checked here, on the reply, rather than left to whichever query
 * happened to load it.
 *
 * **Nothing edits or deletes a reply.** A wrong reply is corrected by another reply; the model
 * refuses both below the gate, and these explicit `false`s keep the buttons off the screen.
 */
final class TicketReplyPolicy
{
    use ChecksSupportPermissions;

    public const MODULE = 'support_tickets';

    public function view(User $user, TicketReply $reply): bool
    {
        $ticket = $reply->ticket;

        if ($ticket === null || ! $user->can('view', $ticket)) {
            return false;
        }

        if ($reply->visibility !== ReplyVisibility::InternalNote) {
            return true;
        }

        return $user->can(self::MODULE.'.view_any');
    }

    public function update(User $user, TicketReply $reply): bool
    {
        return false;
    }

    public function delete(User $user, TicketReply $reply): bool
    {
        return false;
    }

    public function forceDelete(User $user, TicketReply $reply): bool
    {
        return false;
    }

    public function download(User $user, TicketReply $reply): bool
    {
        return $this->view($user, $reply);
    }
}
