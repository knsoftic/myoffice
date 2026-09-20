<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task reached `completed` (phase-06 §2.13.3) — fired beside `TaskStatusChanged` so a listener that
 * only cares about completion does not have to inspect a status pair.
 */
final class TaskCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly ?int $actorId = null,
    ) {}
}
