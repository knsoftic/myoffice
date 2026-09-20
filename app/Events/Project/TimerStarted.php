<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A worker started a timer (phase-06 §6.4). At most one of these can be live per worker at a time, which
 * `uq_te_running` decides rather than the service (INV-P4).
 */
final class TimerStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly ?int $actorId = null,
    ) {}
}
