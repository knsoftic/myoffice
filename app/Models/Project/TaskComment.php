<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\CommentVisibility;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One comment on a task (phase-06 §2.7, requirements §22 and §59).
 *
 * `body` is **plain text**: stored raw and escaped on render. `RichText` (D25) is deliberately not in this
 * path — a task conversation needs no HTML, and not storing any is the cheapest way to be sure none is
 * ever echoed.
 *
 * `author_name` is a snapshot, so deleting a user does not erase the conversation.
 *
 * `visibility` is `internal` (staff only) or `team` (includes the project's collaborators, §112). There is
 * no `client` case: the client panel is read-only over projects, milestones, tasks and files and never
 * sees the task conversation (§7.7).
 *
 * A comment is editable by its author only, and only inside `projects.task_comment_edit_minutes`; every
 * edit and delete writes an activity row carrying the old body (§107), which is why `edited_at` exists
 * rather than a silent in-place update.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $user_id
 * @property string $author_name
 * @property string $body
 * @property CommentVisibility $visibility
 * @property int $mention_count
 * @property Carbon|null $edited_at
 */
class TaskComment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'task_comments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'body',
        'visibility',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'team',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'user_id' => 'integer',
            'visibility' => CommentVisibility::class,
            'mention_count' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'tasks';
    }

    protected function activityModule(): ?string
    {
        return 'tasks';
    }

    /**
     * Has this comment been edited since it was posted?
     */
    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * The comments a collaborator on the project may read (§112).
     */
    public function scopeVisibleToCollaborator(Builder $query): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('visibility'),
            CommentVisibility::valuesVisibleToCollaborator()
        );
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(TaskCommentMention::class, 'task_comment_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
