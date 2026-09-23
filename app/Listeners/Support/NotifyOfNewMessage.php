<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Events\Support\MessageSent;
use App\Services\Support\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Everybody live in the thread, except the sender and anybody who muted it (§10.3
 * `message.received`).
 *
 * **`is_muted` silences the notification, never the unread count.** Somebody who muted a busy group
 * asked to stop being interrupted, not to stop being able to find what they missed — the badge in
 * the sidebar still moves, and the thread is still bold when they open the list. Muting that
 * counted as "mark as read" would lose messages.
 *
 * **`left_at IS NULL` is the same clause `threadsFor()` uses.** A notification about a thread the
 * list will not show is one that cannot be opened.
 *
 * **The body is an excerpt, not the message.** A notification row is readable by anybody who can
 * read the recipient's bell — a shoulder, a shared screen, a lock-screen preview — and a private
 * message reproduced in full there has left the thread it was scoped to.
 */
final class NotifyOfNewMessage
{
    use BuildsTicketLinks;

    private const EXCERPT = 120;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(MessageSent $event): void
    {
        $conversation = $event->conversation;
        $message = $event->message;
        $senderId = (int) $message->getAttribute('user_id');

        $recipients = DB::table('conversation_participants')
            ->where('conversation_id', $conversation->getKey())
            ->whereNull('left_at')
            ->where('user_id', '!=', $senderId)
            ->where('is_muted', 0)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($recipients === []) {
            return;
        }

        $sender = DB::table('users')->where('id', $senderId)->value('name');
        $subject = $conversation->getAttribute('subject');

        $this->notifications->dispatch('message.received', AudienceInput::of($recipients), [
            'title' => $subject !== null && $subject !== ''
                ? sprintf('%s wrote in "%s"', $sender, $subject)
                : sprintf('%s sent you a message', $sender),
            'body' => $this->excerpt((string) $message->getAttribute('body'), (int) $message->getAttribute('attachments_count')),
            'url' => $this->conversationUrl((int) $conversation->getKey()),
            'conversation_id' => (int) $conversation->getKey(),
            'message_id' => (int) $message->getKey(),
        ]);
    }

    private function excerpt(string $body, int $attachments): string
    {
        $text = trim(preg_replace('~\s+~u', ' ', $body) ?? '');

        if ($text === '') {
            return $attachments === 1 ? 'Sent an attachment.' : sprintf('Sent %d attachments.', $attachments);
        }

        return mb_strlen($text) > self::EXCERPT
            ? rtrim(mb_substr($text, 0, self::EXCERPT)).'…'
            : $text;
    }
}
