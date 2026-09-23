<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The `database` channel, plus the five columns the bell actually queries on (§6.19, §10.3).
 *
 * **The stock channel writes `id`, `type`, `data` and `read_at` and nothing else**, so `event_key`,
 * `module`, `level`, `url` and `actor_id` would be left to their defaults — and `event_key` is
 * `NOT NULL` with none, so the insert would simply fail.
 *
 * **Filling them afterwards with an UPDATE cannot work**, and it is worth saying why, because it is
 * the obvious first attempt: every notification here is `ShouldQueue`, so the row is written by a
 * worker some time after `dispatch()` has returned. An update issued straight afterwards matches
 * nothing at all, and one issued later could not tell this dispatch's rows from the next one's.
 * The columns have to be part of the insert, and this is where the insert is built.
 *
 * **Why columns rather than reading `data`?** The bell filters by level, by module and by read
 * state, and a filter on a JSON field can use no index. `notifications` grows without bound —
 * §2.25 archives, never deletes — so "unread, newest first, for this user" has to stay one indexed
 * lookup rather than a scan that decodes every row.
 */
final class RichDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if (method_exists($notification, 'databaseColumns')) {
            // The notification's own columns come second, so a notification cannot overwrite `id`,
            // `type` or `data` by naming one of them — those belong to the channel.
            $payload = array_merge($notification->databaseColumns($notifiable), $payload);
        }

        return $payload;
    }
}
