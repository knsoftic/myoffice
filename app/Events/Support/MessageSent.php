<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\Conversation;
use App\Models\Support\Message;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody wrote in a thread (§6.18 `send()`, §10.3 `message.received`).
 *
 * **Everybody live in the thread except the sender**, and `is_muted` is honoured — a person who
 * muted a busy group asked not to be told about it, and the unread count in the sidebar still moves
 * so nothing is lost.
 */
final class MessageSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly ?int $actorId = null,
    ) {}
}
