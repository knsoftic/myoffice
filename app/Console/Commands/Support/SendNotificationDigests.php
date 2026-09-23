<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Notifications\Support\DailyDigestNotification;
use App\Services\Support\NotificationService;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as Notifier;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `notifications:digest` — daily at `support.notification_digest_hour` (phase-19-23 §10.5).
 *
 * **A digest is assembled from what is already in the bell, never from a second send.** Every row in
 * it was written when the event happened; the digest is one mail listing the ones the person asked
 * to have batched rather than mailed one at a time.
 *
 * `emailed_at` is stamped on exactly the rows the digest carried, after it is queued — a stamp on
 * "everything unread" would silently swallow anything that arrived in between.
 *
 * `--dry-run` prints what it would send and stamps nothing, which is how you check a digest without
 * spending it.
 */
#[AsCommand(name: 'notifications:digest')]
final class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:digest {--dry-run : Print what would be sent and stamp nothing}';

    protected $description = 'Send the daily notification digest to everybody who asked for one';

    public function handle(NotificationService $notifications): int
    {
        if (! Modules::enabled('notifications')) {
            $this->info('The notifications module is disabled: no digests sent.');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $digests = $notifications->pendingDigests($now);

        if ($digests === []) {
            $this->info('Nobody has a digest waiting.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        foreach ($digests as $digest) {
            $user = $digest['user'];
            $rows = $digest['rows'];

            if ($dryRun) {
                $this->line(sprintf('  %s — %d row(s)', $user->getAttribute('name'), $rows->count()));

                continue;
            }

            Notifier::send([$user], new DailyDigestNotification($rows->all()));

            $notifications->markEmailed(
                $rows->map(static fn (object $row): string => (string) $row->id)->all(),
                $now,
            );

            $sent++;
        }

        $this->info($dryRun
            ? sprintf('%d digest(s) would be sent.', count($digests))
            : sprintf('%d digest(s) sent.', $sent));

        return self::SUCCESS;
    }
}
