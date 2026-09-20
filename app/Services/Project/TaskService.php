<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\DataObjects\Project\TaskData;
use App\Enums\ProjectMemberRole;
use App\Enums\TaskStatus;
use App\Enums\TimerStopReason;
use App\Events\Project\TaskAssigned;
use App\Events\Project\TaskCompleted;
use App\Events\Project\TaskCreated;
use App\Events\Project\TaskDeleted;
use App\Events\Project\TaskStatusChanged;
use App\Events\Project\TaskUpdated;
use App\Models\Project\Project;
use App\Models\Project\ProjectMember;
use App\Models\Project\ProjectMilestone;
use App\Models\Project\Task;
use App\Models\Project\TimeEntry;
use App\Models\User;
use App\Services\Project\Exceptions\InvalidStatusTransition;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tasks, subtasks and the Kanban board (phase-06 §6.1, §6.5, requirement §22).
 *
 * **Subtasks are one level deep** (INV-P12): `depth` is derived here from `parent_task_id` and never
 * accepted from a request, `chk_tasks_depth` backs it in the database, and a subtask of a subtask is
 * refused with a sentence rather than a constraint error.
 *
 * **Assignment is single-valued and earned** (INV-P10): a task has at most one assignee, who must be an
 * **active member of the project**. A collaborator assignee must additionally hold a project role whose
 * `canLogTime()` is true — giving work to an observer would create a task nobody is allowed to work on.
 *
 * **A board drop writes one row** (§6.5). `board_position` is `decimal(20,10)` and a move takes the
 * midpoint of its neighbours, so dragging a card never renumbers a column. Only when the gap closes below
 * `0.0000001` does {@see move()} renormalise that one column to `1, 2, 3 …` inside the same transaction
 * and hand the new order back so the client can resync. Two identical positions are legal and break on
 * `id` — a cosmetic ordering question, never lost work.
 *
 * Completing a task is refused while a non-cancelled subtask is still open, and the message **names
 * them**: "finish the subtasks first" without saying which is a puzzle, not an error.
 */
final readonly class TaskService
{
    /** §6.5: below this gap the column is renumbered rather than halved again. */
    private const MIN_GAP = '0.0000001';

    public function __construct(
        private ProjectProgressService $progress,
        private TaskCacheService $caches,
    ) {}

    public function create(TaskData $data, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($data, $actor): Task {
            $attributes = $data->attributes();

            $project = Project::query()->findOrFail($attributes['project_id'] ?? 0);
            $this->lockProject($project);

            $parent = null;

            if (! empty($attributes['parent_task_id'])) {
                $parent = Task::query()->findOrFail($attributes['parent_task_id']);

                if ($parent->depth > 0) {
                    throw ProjectRuleException::subtaskOfSubtask();
                }

                if ((int) $parent->project_id !== (int) $project->getKey()) {
                    throw ProjectRuleException::refuse('parent_task_id', 'That task belongs to a different project.');
                }
            }

            $attributes['depth'] = $parent === null ? 0 : 1;
            $attributes['reporter_id'] = $actor?->getKey();
            $attributes['status'] = TaskStatus::Todo->value;
            $attributes['board_position'] = $this->nextPosition($project, TaskStatus::Todo);

            if (! empty($attributes['project_milestone_id'])) {
                $this->assertMilestoneBelongs($project, (int) $attributes['project_milestone_id']);
            }

            $task = new Task;
            $task->forceFill($attributes)->save();

            $this->caches->syncWithParent($task);
            $this->progress->forget();
            $this->progress->recalculateTask($task->refresh());

            TaskCreated::dispatch($task, $actor?->getKey());

            return $task->refresh();
        });
    }

    public function update(Task $task, TaskData $data, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($task, $data, $actor): Task {
            $this->lockTask($task);

            $attributes = $data->attributes(onlyProvided: true);

            // Re-parenting is not an edit: it would change depth, and a task with subtasks cannot become
            // one. The form does not offer it, and neither does this.
            unset($attributes['parent_task_id'], $attributes['project_id']);

            if (array_key_exists('project_milestone_id', $attributes) && $attributes['project_milestone_id'] !== null) {
                $this->assertMilestoneBelongs($task->project, (int) $attributes['project_milestone_id']);
            }

            $previousMilestone = $task->project_milestone_id;
            $previousEstimate = $task->estimated_minutes;

            $task->forceFill($attributes)->save();

            // Either of these moves a weight in §6.3, so the chain has to be walked again.
            if ($task->project_milestone_id !== $previousMilestone || $task->estimated_minutes !== $previousEstimate) {
                $this->progress->forget();
                $this->progress->recalculateTask($task->refresh());

                if ($previousMilestone !== null) {
                    $old = ProjectMilestone::query()->find($previousMilestone);

                    if ($old !== null) {
                        $this->progress->recalculateMilestone($old);
                    }
                }
            }

            TaskUpdated::dispatch($task, $actor?->getKey());

            return $task->refresh();
        });
    }

    /**
     * Give the task to somebody, or take it off them by passing neither.
     */
    public function assign(
        Task $task,
        ?User $user,
        ?int $collaboratorId,
        ?string $note = null,
        ?User $actor = null,
    ): Task {
        if ($user !== null && $collaboratorId !== null) {
            throw ProjectRuleException::refuse('assigned_user_id', 'A task has one assignee, not two.');
        }

        return DB::transaction(function () use ($task, $user, $collaboratorId, $note, $actor): Task {
            $this->lockTask($task);

            $previousUser = $task->assigned_user_id;
            $previousCollaborator = $task->assigned_collaborator_id;

            if ($user !== null) {
                $this->assertActiveMember($task->project, $user->getKey(), null);
            }

            if ($collaboratorId !== null) {
                $role = $this->assertActiveMember($task->project, null, $collaboratorId);

                if (! $role->canLogTime()) {
                    throw ProjectRuleException::collaboratorCannotLogTime();
                }
            }

            $task->forceFill([
                'assigned_user_id' => $user?->getKey(),
                'assigned_collaborator_id' => $collaboratorId,
                'assigned_at' => $user === null && $collaboratorId === null ? null : now(),
                'assigned_by' => $actor?->getKey(),
            ])->save();

            TaskAssigned::dispatch($task, $previousUser, $previousCollaborator, $note, $actor?->getKey());

            return $task->refresh();
        });
    }

    /**
     * Move a task between the six statuses of §22, by the §2.13.3 table only.
     */
    public function changeStatus(Task $task, TaskStatus $to, ?string $reason = null, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($task, $to, $reason, $actor): Task {
            $this->lockTask($task);

            $from = $task->status;

            if ($from === $to) {
                return $task;
            }

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between($from, $to, $from->allowedTransitions());
            }

            if ($to === TaskStatus::Blocked && trim((string) $reason) === '') {
                throw ProjectRuleException::blockedReasonRequired();
            }

            if ($from->requiresReasonFor($to) && trim((string) $reason) === '') {
                throw ProjectRuleException::reasonRequired('reason', $to === TaskStatus::Cancelled
                    ? 'Say why the task is being cancelled.'
                    : 'Say why the task is being reopened.');
            }

            if ($to === TaskStatus::Completed) {
                $this->assertSubtasksClosed($task);
            }

            $attributes = ['status' => $to->value];

            $attributes['blocked_reason'] = $to === TaskStatus::Blocked ? $reason : null;

            if ($to === TaskStatus::Completed) {
                $attributes['completed_at'] = now();
                $attributes['completed_by'] = $actor?->getKey();
            } elseif ($from === TaskStatus::Completed) {
                $attributes['completed_at'] = null;
                $attributes['completed_by'] = null;
            }

            $task->forceFill($attributes)->save();

            if ($to->stopsRunningTimer()) {
                $this->stopTimersOn($task, $actor);
            }

            $this->caches->syncWithParent($task->refresh());
            $this->progress->forget();
            $this->progress->recalculateTask($task->refresh());

            TaskStatusChanged::dispatch($task, $from, $to, $reason, $actor?->getKey());

            if ($to === TaskStatus::Completed) {
                TaskCompleted::dispatch($task, $actor?->getKey());
            }

            return $task->refresh();
        });
    }

    /**
     * The Kanban drop: a status move and a reposition, in one transaction (§6.5).
     *
     * Returns the column the card landed in, in its new order — the client resyncs from that rather than
     * guessing, which is what makes a renormalisation invisible to the person dragging.
     *
     * @return array{task: Task, column: list<array{id: int, position: string}>, renormalised: bool}
     */
    public function move(Task $task, TaskStatus $to, ?int $afterId = null, ?int $beforeId = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($task, $to, $afterId, $beforeId, $actor): array {
            $this->lockTask($task);

            $from = $task->status;

            if ($from !== $to) {
                if (! $from->canTransitionTo($to)) {
                    throw InvalidStatusTransition::between($from, $to, $from->allowedTransitions());
                }

                if ($to->requiresReason()) {
                    throw ProjectRuleException::refuse(
                        'status',
                        $to === TaskStatus::Blocked
                            ? 'Blocking a task needs a reason, so it cannot be done by dragging.'
                            : 'Cancelling a task needs a reason, so it cannot be done by dragging.'
                    );
                }

                if ($to === TaskStatus::Completed) {
                    $this->assertSubtasksClosed($task);
                }
            }

            $position = $this->positionBetween($task->project, $to, $afterId, $beforeId);

            $attributes = ['board_position' => $position['position']];

            if ($from !== $to) {
                $attributes['status'] = $to->value;

                if ($to === TaskStatus::Completed) {
                    $attributes['completed_at'] = now();
                    $attributes['completed_by'] = $actor?->getKey();
                } elseif ($from === TaskStatus::Completed) {
                    $attributes['completed_at'] = null;
                    $attributes['completed_by'] = null;
                }
            }

            $task->forceFill($attributes)->save();

            $renormalised = $position['renormalise'];

            if ($renormalised) {
                $this->renormalise($task->project, $to);
            }

            if ($from !== $to) {
                if ($to->stopsRunningTimer()) {
                    $this->stopTimersOn($task, $actor);
                }

                $this->caches->syncWithParent($task->refresh());
                $this->progress->forget();
                $this->progress->recalculateTask($task->refresh());

                TaskStatusChanged::dispatch($task, $from, $to, null, $actor?->getKey());

                if ($to === TaskStatus::Completed) {
                    TaskCompleted::dispatch($task, $actor?->getKey());
                }
            }

            return [
                'task' => $task->refresh(),
                'column' => $this->column($task->project, $to),
                'renormalised' => $renormalised,
            ];
        });
    }

    /**
     * Soft delete. Refused while a timer is running on the task; its time entries are kept and keep
     * counting for the project, because the hours were really worked.
     */
    public function delete(Task $task, ?User $actor = null): void
    {
        DB::transaction(function () use ($task, $actor): void {
            $this->lockTask($task);

            if ($this->hasLiveTimer($task)) {
                throw ProjectRuleException::taskHasRunningTimer();
            }

            $parent = $task->parent;
            $milestone = $task->milestone;

            $task->delete();

            if ($parent !== null) {
                $this->caches->sync($parent);
            }

            $this->progress->forget();

            if ($parent !== null) {
                $this->progress->recalculateTask($parent->refresh());
            } elseif ($milestone !== null) {
                $this->progress->recalculateMilestone($milestone);
            } else {
                $this->progress->recalculateProject($task->project);
            }

            TaskDeleted::dispatch($task, $actor?->getKey());
        });
    }

    public function restore(Task $task, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $task->restore();

            $this->caches->syncWithParent($task->refresh());
            $this->progress->forget();
            $this->progress->recalculateTask($task->refresh());

            TaskUpdated::dispatch($task, $actor?->getKey());

            return $task->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | §6.5 — fractional board ordering
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{position: string, renormalise: bool}
     */
    private function positionBetween(Project $project, TaskStatus $column, ?int $afterId, ?int $beforeId): array
    {
        $after = $afterId === null ? null : $this->positionOf($project, $column, $afterId);
        $before = $beforeId === null ? null : $this->positionOf($project, $column, $beforeId);

        if ($after === null && $before === null) {
            return ['position' => $this->nextPosition($project, $column), 'renormalise' => false];
        }

        if ($after === null) {
            return ['position' => Money::round(Money::sub($before, '1'), 10), 'renormalise' => false];
        }

        if ($before === null) {
            return ['position' => Money::round(Money::add($after, '1'), 10), 'renormalise' => false];
        }

        $gap = Money::sub($before, $after);

        if (Money::compare($gap, self::MIN_GAP) <= 0) {
            // The column has run out of room between these two cards. Take the midpoint anyway so the
            // drop lands, then renumber the column in the same transaction.
            return ['position' => Money::round(Money::add($after, Money::div($gap, '2')), 10), 'renormalise' => true];
        }

        return [
            'position' => Money::round(Money::add($after, Money::div($gap, '2')), 10),
            'renormalise' => false,
        ];
    }

    private function positionOf(Project $project, TaskStatus $column, int $taskId): ?string
    {
        $position = Task::query()
            ->where('project_id', $project->getKey())
            ->where('status', $column->value)
            ->whereKey($taskId)
            ->value('board_position');

        return $position === null ? null : (string) $position;
    }

    private function nextPosition(Project $project, TaskStatus $column): string
    {
        $max = Task::query()
            ->where('project_id', $project->getKey())
            ->where('status', $column->value)
            ->max('board_position');

        return Money::round(Money::add((string) ($max ?? '0'), '1'), 10);
    }

    private function renormalise(Project $project, TaskStatus $column): void
    {
        $position = 0;

        foreach ($this->orderedColumn($project, $column) as $task) {
            $position++;

            DB::table('tasks')->where('id', $task->id)->update([
                'board_position' => Money::round((string) $position, 10),
            ]);
        }
    }

    /**
     * @return list<array{id: int, position: string}>
     */
    private function column(Project $project, TaskStatus $column): array
    {
        return $this->orderedColumn($project, $column)
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'position' => (string) $row->board_position,
            ])
            ->all();
    }

    /**
     * @return Collection<int, object>
     */
    private function orderedColumn(Project $project, TaskStatus $column): Collection
    {
        return DB::table('tasks')
            ->select('id', 'board_position')
            ->where('project_id', $project->getKey())
            ->where('status', $column->value)
            ->whereNull('deleted_at')
            ->orderBy('board_position')
            ->orderBy('id')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    private function assertSubtasksClosed(Task $task): void
    {
        $open = $task->subtasks()
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->pluck('title');

        if ($open->isNotEmpty()) {
            throw ProjectRuleException::openSubtasks($open->implode(', '));
        }
    }

    private function assertMilestoneBelongs(Project $project, int $milestoneId): void
    {
        $belongs = ProjectMilestone::query()
            ->whereKey($milestoneId)
            ->where('project_id', $project->getKey())
            ->exists();

        if (! $belongs) {
            throw ProjectRuleException::milestoneNotOnProject();
        }
    }

    private function assertActiveMember(Project $project, ?int $userId, ?int $collaboratorId): ProjectMemberRole
    {
        $member = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->when($collaboratorId !== null, fn ($query) => $query->where('collaborator_id', $collaboratorId))
            ->first();

        if ($member === null) {
            throw ProjectRuleException::notAProjectMember();
        }

        return $member->role;
    }

    private function hasLiveTimer(Task $task): bool
    {
        return TimeEntry::query()
            ->where('task_id', $task->getKey())
            ->whereIn('status', ['running', 'paused'])
            ->exists();
    }

    private function stopTimersOn(Task $task, ?User $actor): void
    {
        $timer = app(TimerService::class);

        TimeEntry::query()
            ->where('task_id', $task->getKey())
            ->whereIn('status', ['running', 'paused'])
            ->get()
            ->each(fn (TimeEntry $entry) => $timer->stop($entry, TimerStopReason::Stop, $actor));
    }

    private function lockProject(Project $project): void
    {
        DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->value('id');
    }

    private function lockTask(Task $task): void
    {
        DB::table('tasks')->where('id', $task->getKey())->lockForUpdate()->value('id');
        $task->refresh();
    }
}
