<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Models\Concerns\Blameable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person mentioned in one task comment (phase-06 §2.8, requirement §22).
 *
 * A row per mention is what makes "mentions of me" an index lookup instead of a `LIKE '%@name%'` scan over
 * every comment body, and `uq_tcm_pair` gives one row per person per comment however many times the handle
 * appears — so the notification cannot be sent twice.
 *
 * **Append-only, no soft deletes** (D19): a history pivot with no independent life. It exists while its
 * comment does, which is what `cascadeOnDelete` on both sides says.
 *
 * Only users who are **active members of the comment's project** (or its manager) may be mentioned — the
 * service enforces that, so the autocomplete cannot become a way to enumerate staff.
 *
 * @property int $id
 * @property int $task_comment_id
 * @property int $user_id
 * @property Carbon|null $notified_at
 */
class TaskCommentMention extends Model
{
    use Blameable;

    protected $table = 'task_comment_mentions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_comment_id',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_comment_id' => 'integer',
            'user_id' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'task_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
