<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use App\Enums\TimerStopReason;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A timer was stopped (phase-06 §6.4). `$reason` says whether the worker stopped it, switched to other
 * work, or the scheduled sweep closed it.
 */
final class TimerStopped implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly TimerStopReason $reason,
        public readonly ?int $actorId = null,
    ) {}
}
