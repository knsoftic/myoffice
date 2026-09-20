<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\Priority;
use App\Enums\ProjectMemberRole;
use App\Enums\TaskStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\Project\Concerns\GuardsServiceOwnedColumns;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One task, or one subtask, of a project (phase-06 §2.5, requirement §22).
 *
 * **One level deep, always** (INV-P12): `depth = 1` exactly when `parent_task_id` is set, enforced by
 * `chk_tasks_depth` in the database and by the `saving` hook here, so a subtask of a subtask is impossible
 * whichever way the row is written.
 *
 * **At most one assignee** (INV-P10): a staff `assigned_user_id` ([D-P6-2] / D32) or an
 * `assigned_collaborator_id`, never both — `chk_tasks_assignee`.
 *
 * Every count on this row is a **cache recomputed by COUNT/SUM under a row lock, never incremented**
 * (INV-P6): `checklist_*`, `subtask_*`, `comment_count`, `attachment_count`, `actual_seconds`. That is what
 * makes them self-healing and a replayed job harmless, and it is why they are absent from `$fillable`.
 * `estimated_hours`, `actual_minutes` and `actual_hours` are STORED generated columns on top (INV-P7): §22
 * asks for actual hours and they are unwritable by construction.
 *
 * **What the client may see** is the AND of two switches (§7.7, §9, F-3.1): the global setting
 * `projects.client_can_see_tasks` and this row's `is_client_visible`. {@see scopeVisibleToClient()} is the
 * per-row half; the setting is a short-circuit the policy applies. Clearing the per-row flag is an
 * ordinary, activity-logged edit.
 *
 * `board_position` is `decimal(20,10)` for §6.5's fractional Kanban ordering: a drop between two cards
 * takes the midpoint and touches one row.
 *
 * The task's history is `activity_log` filtered by subject (D13) — there is no per-task history table.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $project_milestone_id
 * @property int|null $parent_task_id
 * @property int $depth
 * @property string $title
 * @property string|null $description
 * @property int|null $assigned_user_id
 * @property int|null $assigned_collaborator_id
 * @property Carbon|null $assigned_at
 * @property int|null $assigned_by
 * @property int|null $reporter_id
 * @property Priority $priority
 * @property TaskStatus $status
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 * @property int|null $estimated_minutes
 * @property string|null $estimated_hours generated STORED
 * @property int $actual_seconds
 * @property int $actual_minutes generated STORED
 * @property string $actual_hours generated STORED (INV-P7)
 * @property string $progress_percent
 * @property int $checklist_total
 * @property int $checklist_done
 * @property int $subtask_total
 * @property int $subtask_done
 * @property int $comment_count
 * @property int $attachment_count
 * @property bool $is_client_visible
 * @property string $board_position
 * @property string|null $blocked_reason
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 */
class Task extends Model
{
    use Blameable;
    use GuardsServiceOwnedColumns;
    use LogsActivityWithContext;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** Phase 8 (phase-08-09 §2.1). */
    public const COLLABORATOR_MODEL = 'App\\Models\\Collaborator\\Collaborator';

    /** INV-P8 — `ProjectProgressService` owns this. */
    public const PROGRESS_COLUMNS = ['progress_percent'];

    public const GROUP_PROGRESS = 'progress';

    protected $table = 'tasks';

    /**
     * What a task form may carry. Assignment, status, the caches and `board_position` each have their own
     * service method (§6.1) and are written with `forceFill()` there.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'project_milestone_id',
        'parent_task_id',
        'title',
        'description',
        'priority',
        'start_date',
        'due_date',
        'estimated_minutes',
        'is_client_visible',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'todo',
        'depth' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'project_milestone_id' => 'integer',
            'parent_task_id' => 'integer',
            'depth' => 'integer',
            'assigned_user_id' => 'integer',
            'assigned_collaborator_id' => 'integer',
            'assigned_at' => 'datetime',
            'assigned_by' => 'integer',
            'reporter_id' => 'integer',
            'priority' => Priority::class,
            'status' => TaskStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'estimated_minutes' => 'integer',
            'estimated_hours' => 'decimal:2',
            'actual_seconds' => 'integer',
            'actual_minutes' => 'integer',
            'actual_hours' => 'decimal:2',
            'progress_percent' => 'decimal:4',
            'checklist_total' => 'integer',
            'checklist_done' => 'integer',
            'subtask_total' => 'integer',
            'subtask_done' => 'integer',
            'comment_count' => 'integer',
            'attachment_count' => 'integer',
            'is_client_visible' => 'boolean',
            'board_position' => 'decimal:10',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (Task $task): void {
            $parent = $task->getAttribute('parent_task_id');
            $depth = (int) $task->getAttribute('depth');

            if (($parent === null) !== ($depth === 0)) {
                throw new LogicException(sprintf(
                    'Task #%s: depth is 1 exactly when parent_task_id is set (phase-06 INV-P12, '
                    .'chk_tasks_depth); this row has parent_task_id %s with depth %d.',
                    (string) ($task->getKey() ?? 'new'),
                    $parent === null ? 'NULL' : (string) $parent,
                    $depth
                ));
            }

            if ($depth > 1) {
                throw new LogicException(sprintf(
                    'Task #%s: subtasks are exactly one level deep (phase-06 INV-P12); depth %d was asked for.',
                    (string) ($task->getKey() ?? 'new'),
                    $depth
                ));
            }

            if ($task->getAttribute('assigned_user_id') !== null
                && $task->getAttribute('assigned_collaborator_id') !== null) {
                throw new LogicException(sprintf(
                    'Task #%s: a task has at most one assignee (phase-06 INV-P10, chk_tasks_assignee).',
                    (string) ($task->getKey() ?? 'new')
                ));
            }
        });

        static::updating(static function (Task $task): void {
            $task->refuseGuardedColumns(
                self::GROUP_PROGRESS,
                self::PROGRESS_COLUMNS,
                'ProjectProgressService',
                'INV-P8'
            );
        });
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
     * The human reference used on screens and in notifications (the spine's `CLE-` pattern).
     */
    public function getReferenceAttribute(): string
    {
        return 'TSK-'.$this->getKey();
    }

    public function isSubtask(): bool
    {
        return $this->parent_task_id !== null;
    }

    /**
     * The per-row half of the §9 client rule (F-3.1).
     *
     * The other half, `projects.client_can_see_tasks`, is a **global setting**, not a column (§5): it is a
     * boolean short-circuit the policy and the controller apply — when it is off the client task route
     * 404s outright rather than running a query that returns nothing. Keeping it out of here also keeps
     * the model free of a settings dependency.
     */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_client_visible'), true);
    }

    /**
     * The single definition of "this user may see this task" (§9).
     *
     * Always inside the projects they can see. `tasks.view_any` stops there; without it the set narrows to
     * the tasks they are assigned, the ones they reported, and everything inside a project where their
     * membership role can manage — a lead needs the whole board, a developer needs their own cards.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('tasks.project_id', Project::query()->visibleTo($user)->select('projects.id'));

        if ($user->can('tasks.view_any')) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped
                ->where('tasks.assigned_user_id', $user->getKey())
                ->orWhere('tasks.reporter_id', $user->getKey())
                ->orWhereIn('tasks.project_id', ProjectMember::query()
                    ->whereNull('deleted_at')
                    ->where('user_id', $user->getKey())
                    ->whereIn('role', [ProjectMemberRole::Manager->value, ProjectMemberRole::Lead->value])
                    ->select('project_id'));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.12)
    |--------------------------------------------------------------------------
    */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedCollaborator(): BelongsTo
    {
        return $this->belongsTo(
            $this->laterPhaseModel(self::COLLABORATOR_MODEL, 'Phase 8'),
            'assigned_collaborator_id'
        );
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class, 'task_id')->orderBy('sort_order');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'task_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
