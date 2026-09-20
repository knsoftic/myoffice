<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\CommentVisibility;
use App\Events\Project\TaskCommentDeleted;
use App\Events\Project\TaskCommentEdited;
use App\Events\Project\TaskCommentPosted;
use App\Events\Project\UserMentionedInComment;
use App\Models\Project\ProjectMember;
use App\Models\Project\Task;
use App\Models\Project\TaskComment;
use App\Models\Project\TaskCommentMention;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The task conversation (phase-06 §6.1, requirements §22 and §59).
 *
 * **A mention resolves only against people who are already on the project.** An unknown or non-member
 * handle is left as plain text rather than reported as "no such user", so the autocomplete cannot be used
 * to find out who works here — a mention box is a directory if you let it be one.
 *
 * `uq_tcm_pair` gives one row per person per comment however many times their handle appears, so a
 * notification cannot be sent twice for one comment. A person who mentions **themselves** is not
 * notified: they know.
 *
 * Editing belongs to the author, inside `projects.task_comment_edit_minutes`, and the old body goes to
 * the activity log (§107). A **new** mention introduced by an edit notifies; the ones already there do
 * not notify again.
 */
final readonly class TaskCommentService
{
    public function __construct(private TaskCacheService $caches) {}

    /**
     * @param  list<int>  $mentionedUserIds  ids the client picked; each is still checked against the team
     */
    public function post(
        Task $task,
        User $author,
        string $body,
        CommentVisibility $visibility = CommentVisibility::Team,
        array $mentionedUserIds = [],
    ): TaskComment {
        $body = trim($body);

        if ($body === '') {
            throw ProjectRuleException::refuse('body', 'Write something first.');
        }

        return DB::transaction(function () use ($task, $author, $body, $visibility, $mentionedUserIds): TaskComment {
            $comment = new TaskComment;
            $comment->forceFill([
                'task_id' => $task->getKey(),
                'user_id' => $author->getKey(),
                // Snapshotted, so deleting the account does not erase the conversation.
                'author_name' => $author->name,
                'body' => $body,
                'visibility' => $visibility->value,
            ])->save();

            $mentioned = $this->recordMentions($comment, $task, $author, $mentionedUserIds);

            $comment->forceFill(['mention_count' => $mentioned->count()])->save();

            $this->caches->sync($task);

            TaskCommentPosted::dispatch($comment, $author->getKey());

            foreach ($mentioned as $userId) {
                UserMentionedInComment::dispatch($comment, $userId, $author->getKey());
            }

            return $comment->refresh();
        });
    }

    /**
     * @param  list<int>  $mentionedUserIds
     */
    public function edit(TaskComment $comment, User $actor, string $body, array $mentionedUserIds = []): TaskComment
    {
        $body = trim($body);

        if ($body === '') {
            throw ProjectRuleException::refuse('body', 'A comment cannot be emptied — delete it instead.');
        }

        $minutes = (int) setting('projects.task_comment_edit_minutes', 15);

        if ($comment->created_at === null || $comment->created_at->lessThan(now()->subMinutes($minutes))) {
            throw ProjectRuleException::commentWindowClosed($minutes);
        }

        return DB::transaction(function () use ($comment, $actor, $body, $mentionedUserIds): TaskComment {
            $previous = (string) $comment->body;

            $comment->forceFill(['body' => $body, 'edited_at' => now()])->save();

            $before = $comment->mentions()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $after = $this->recordMentions($comment, $comment->task, $actor, $mentionedUserIds);

            $comment->forceFill(['mention_count' => $after->count()])->save();

            TaskCommentEdited::dispatch($comment, $previous, $actor->getKey());

            // Only the people the edit newly named hear about it; the rest were told when it was posted.
            foreach ($after->diff($before) as $userId) {
                UserMentionedInComment::dispatch($comment, $userId, $actor->getKey());
            }

            return $comment->refresh();
        });
    }

    public function delete(TaskComment $comment, User $actor): void
    {
        DB::transaction(function () use ($comment, $actor): void {
            $task = $comment->task;

            $comment->delete();

            if ($task !== null) {
                $this->caches->sync($task);
            }

            TaskCommentDeleted::dispatch($comment, $actor->getKey());
        });
    }

    /**
     * Who may be mentioned on this task: active members plus the project manager.
     *
     * @return Collection<int, User>
     */
    public function mentionable(Task $task, ?string $search = null): Collection
    {
        $ids = ProjectMember::query()
            ->where('project_id', $task->project_id)
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->pluck('user_id');

        if ($task->project?->project_manager_id !== null) {
            $ids->push($task->project->project_manager_id);
        }

        return User::query()
            ->whereIn('id', $ids->unique()->values())
            ->when(filled($search), fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email']);
    }

    /**
     * Write one row per distinct, allowed mention. Returns the user ids notified.
     *
     * @param  list<int>  $requested
     * @return Collection<int, int>
     */
    private function recordMentions(TaskComment $comment, ?Task $task, User $author, array $requested): Collection
    {
        if ($task === null || $requested === []) {
            return collect();
        }

        $allowed = $this->mentionable($task)->pluck('id')->map(fn ($id): int => (int) $id);

        $ids = collect($requested)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->intersect($allowed)
            // Mentioning yourself is allowed; being notified about it is not.
            ->reject(fn (int $id): bool => $id === (int) $author->getKey())
            ->values();

        foreach ($ids as $id) {
            TaskCommentMention::query()->firstOrCreate([
                'task_comment_id' => $comment->getKey(),
                'user_id' => $id,
            ]);
        }

        return $ids;
    }
}
