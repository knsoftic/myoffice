<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Task;
use App\Enums\TaskStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task moved status (phase-06 §2.13.3) — a board drag, a status select, or the side effect of starting
 * a timer on a `todo` task.
 */
final class TaskStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly TaskStatus $from,
        public readonly TaskStatus $to,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
