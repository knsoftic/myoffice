<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paused timer was resumed (phase-06 §6.4) — a new append-only segment, never a reopened old one.
 */
final class TimerResumed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly ?int $actorId = null,
    ) {}
}
