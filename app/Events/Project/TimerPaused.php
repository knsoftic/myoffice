<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A running timer was paused (phase-06 §6.4): the open segment closes and the entry stays live, still
 * holding the worker's single-timer slot.
 */
final class TimerPaused implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly ?int $actorId = null,
    ) {}
}
