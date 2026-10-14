<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Support\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * One bell row, live, to the one person it was written for.
 *
 * Raised by `PushNotificationToBrowser` after the queue worker has stored the row, so the payload
 * describes a row that exists — the same shape `notifications.bell` returns (`BellPayload::present`),
 * plus the fresh unread count so the badge never has to guess.
 *
 * **Broadcast now, not queued again.** It is already running inside the worker that wrote the row; a
 * second queue hop would only add latency to a pop-up and a second failure point.
 */
final class NotificationPushed implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $notification,
        public readonly int $unread,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Realtime::userChannel($this->userId))];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array{notification: array<string, mixed>, unread: int}
     */
    public function broadcastWith(): array
    {
        return ['notification' => $this->notification, 'unread' => $this->unread];
    }
}
