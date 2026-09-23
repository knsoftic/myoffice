<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Services\Support\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `notifications:prune` — weekly (phase-19-23 §10.5).
 *
 * **Only archived rows, and only past `support.notification_retention_days`.** An unread
 * notification is never pruned however old it is: it is somebody's outstanding record of being told
 * something, and age is not consent. A retention of `0` means "keep for ever", which is the honest
 * reading — a zero-day retention that deleted everything nightly would be a footgun with a number
 * on it.
 *
 * This runs whether or not the module is enabled: switching the bell off is not a reason to stop
 * housekeeping a table that only grows.
 */
#[AsCommand(name: 'notifications:prune')]
final class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete archived notifications past the retention window';

    public function handle(NotificationService $notifications): int
    {
        $removed = $notifications->pruneArchived(Carbon::now());

        $this->info(sprintf('%d archived notification(s) pruned.', $removed));

        return self::SUCCESS;
    }
}
