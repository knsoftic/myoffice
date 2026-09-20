<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TaskComment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A comment was edited inside its author's window (phase-06 §6.1). The old body goes to the activity log,
 * never quietly over the top of the original.
 */
final class TaskCommentEdited implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TaskComment $comment,
        public readonly string $previousBody,
        public readonly ?int $actorId = null,
    ) {}
}
