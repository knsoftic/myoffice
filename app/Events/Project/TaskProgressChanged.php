<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task's derived progress moved (phase-06 §6.1 `recalculateTask()`, §10.1).
 *
 * Fired once per row that actually changed, after commit — the cascade walks task -> parent -> milestone
 * -> project and stays silent about the rows whose value it recomputed to the same number.
 */
final class TaskProgressChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
