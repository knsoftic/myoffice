<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One line of a task's checklist (phase-06 §2.6, requirement §22).
 *
 * `done_at` is stamped with `is_done` and `chk_tci_done` keeps the pair honest, so "when was this ticked"
 * is never unanswerable. Toggling an item recomputes `tasks.checklist_total` / `checklist_done` over
 * non-trashed rows by COUNT under a row lock — never by incrementing — and then walks the progress chain
 * (INV-P6, §6.3).
 *
 * @property int $id
 * @property int $task_id
 * @property string $title
 * @property bool $is_done
 * @property Carbon|null $done_at
 * @property int|null $done_by
 * @property int $sort_order
 */
class TaskChecklistItem extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'task_checklist_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'title',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'done_by' => 'integer',
            'sort_order' => 'integer',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
