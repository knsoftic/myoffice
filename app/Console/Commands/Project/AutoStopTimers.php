<?php

declare(strict_types=1);

namespace App\Console\Commands\Project;

use App\Services\Project\TimerService;
use Illuminate\Console\Command;

/**
 * Close timers somebody forgot (phase-06 §6.4, §10.4) — every five minutes.
 *
 * Bounded to 200 rows and idempotent: it only ever touches entries that are still `running`, so running
 * it twice in a minute stops nothing twice. `chk_te_duration` caps a single entry at 24 hours as the
 * database backstop underneath.
 *
 * Every owner is notified, because a timer that vanished without a word reads as lost work.
 */
final class AutoStopTimers extends Command
{
    protected $signature = 'projects:auto-stop-timers {--limit=200}';

    protected $description = 'Stop timers running longer than projects.timer_max_hours.';

    public function handle(TimerService $timers): int
    {
        if (! (bool) setting('projects.timer_auto_stop_enabled', true)) {
            $this->components->info('Automatic timer stopping is switched off.');

            return self::SUCCESS;
        }

        $maxHours = (int) setting('projects.timer_max_hours', 12);
        $stopped = $timers->autoStopStale($maxHours, (int) $this->option('limit'));

        $this->components->info(sprintf('Stopped %d timer(s) past %d hours.', $stopped, $maxHours));

        return self::SUCCESS;
    }
}
