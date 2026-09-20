<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TaskComment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody was @mentioned in a task comment (phase-06 §6.1, requirement §22).
 *
 * Only active members of the comment's project and its manager can be mentioned, and a person mentioned in
 * their own comment is not notified — both decided in the service, before this fires.
 */
final class UserMentionedInComment implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TaskComment $comment,
        public readonly int $userId,
        public readonly ?int $actorId = null,
    ) {}
}
