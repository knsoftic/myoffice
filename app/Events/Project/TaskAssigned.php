<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task was given to somebody, or taken away from them (phase-06 §6.1 `assign()`).
 *
 * Both ends are ids rather than models because either may be a user or a collaborator, and because the
 * previous assignee may since have been removed from the project.
 */
final class TaskAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly ?int $previousUserId = null,
        public readonly ?int $previousCollaboratorId = null,
        public readonly ?string $note = null,
        public readonly ?int $actorId = null,
    ) {}
}
