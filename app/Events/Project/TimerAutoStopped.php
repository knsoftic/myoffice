<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A forgotten timer was closed by `projects:auto-stop-timers` past `projects.timer_max_hours` (§6.4).
 * The owner is notified: a silently truncated timer would read as lost work.
 */
final class TimerAutoStopped implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly int $maxHours,
    ) {}
}
