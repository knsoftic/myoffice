<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Models\Project\TaskComment;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may join a task's conversation (phase-06 §4.1, §4.2, requirement §59).
 *
 * `task_comments` is its own module slug for one reason: **a role must be able to discuss a task without
 * holding `tasks.edit`**. A collaborator gets "add comments" and nothing else, and a developer can answer
 * a question on a card that is not theirs.
 *
 * Reading a comment still needs to reach the task, so {@see view()} forwards to {@see TaskPolicy::view()}
 * — which returns 404 rather than 403 for a task the user cannot see, and that answer carries through.
 *
 * **Editing is the author's, inside a window.** `projects.task_comment_edit_minutes` is the window; after
 * it, the comment is fixed for everybody. Deleting belongs to the author or to whoever holds
 * `task_comments.delete`.
 */
final class TaskCommentPolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'task_comments';

    public function __construct(private readonly TaskPolicy $tasks) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, TaskComment $comment): bool|Response
    {
        if (! $this->holds($user, self::MODULE, Ability::View)) {
            return false;
        }

        return $this->tasks->view($user, $comment->task);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * The author's own comment, inside the edit window. Nobody else, at any permission level: rewriting
     * what somebody said is not an administrative act.
     */
    public function update(User $user, TaskComment $comment): bool|Response
    {
        if ($this->isTrashed($comment)) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::Edit)) {
            return false;
        }

        if ((int) $comment->user_id !== (int) $user->getKey()) {
            return false;
        }

        return $this->withinEditWindow($comment);
    }

    public function delete(User $user, TaskComment $comment): bool|Response
    {
        if ($this->isTrashed($comment)) {
            return false;
        }

        if ((int) $comment->user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->holds($user, self::MODULE, Ability::Delete)
            ? $this->tasks->view($user, $comment->task)
            : false;
    }

    private function withinEditWindow(TaskComment $comment): bool
    {
        $minutes = (int) setting('projects.task_comment_edit_minutes', 15);

        return $comment->created_at !== null
            && $comment->created_at->greaterThanOrEqualTo(now()->subMinutes($minutes));
    }
}
