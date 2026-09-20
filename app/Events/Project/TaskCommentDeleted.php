<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TaskComment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A comment was removed by its author or by somebody holding `task_comments.delete` (phase-06 §6.1).
 */
final class TaskCommentDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TaskComment $comment,
        public readonly ?int $actorId = null,
    ) {}
}
