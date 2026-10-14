<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\BellPayload;
use App\Events\Support\NotificationPushed;
use App\Models\User;
use App\Services\Support\NotificationService;
use App\Support\Realtime;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a notification row has been written, push it to its recipient's open tabs.
 *
 * **Hooked on Laravel's own `NotificationSent`, so nothing that sends a notification changed.**
 * `NotificationService::dispatch()`, the registry, the preferences, the queue and `RichDatabaseChannel`
 * are exactly as they were; this listens after the database channel has stored the row and adds one
 * thing — a WebSocket message on that user's private channel.
 *
 * **It never throws.** This runs inside the queued notification job. An exception here would fail the
 * job and the retry would write the row a second time, so a broadcaster that is down, slow or
 * misconfigured is logged and forgotten: the row is in the bell either way, and the next poll or page
 * load shows it.
 */
final class PushNotificationToBrowser
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if (! Realtime::enabled() || ! $event->notifiable instanceof User || ! $this->isDatabaseChannel($event->channel)) {
            return;
        }

        try {
            $row = DB::table('notifications')->where('id', (string) $event->notification->id)->first();

            // Only the bell's own rows: a row for somebody else, or none at all, pushes nothing.
            if ($row === null || (int) $row->notifiable_id !== (int) $event->notifiable->getKey() || $row->notifiable_type !== User::class) {
                return;
            }

            $user = $event->notifiable;

            event(new NotificationPushed((int) $user->getKey(), BellPayload::present($row), $this->notifications->unreadCountFor($user)));
        } catch (Throwable $exception) {
            Log::warning('Live notification push skipped; the row is stored and the bell will show it.', [
                'notification' => (string) $event->notification->id,
                'user' => (int) $event->notifiable->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** `database`, or a channel class built on it — `RichDatabaseChannel` is how this system writes rows. */
    private function isDatabaseChannel(string $channel): bool
    {
        return $channel === 'database' || is_a($channel, DatabaseChannel::class, true);
    }
}
