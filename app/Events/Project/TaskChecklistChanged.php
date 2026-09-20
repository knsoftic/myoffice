<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A checklist line was added, ticked, renamed, reordered or removed (phase-06 §6.1).
 *
 * One event for all five: every one of them recomputes the same two caches and walks the same progress
 * chain, so a listener that cares about any cares about all.
 */
final class TaskChecklistChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly ?int $actorId = null,
    ) {}
}
