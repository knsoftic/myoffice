<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Delivery;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project\Task;
use App\Support\DateRange;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Throwable;

/**
 * The board's real workload: what is still open, what is late, and what nobody owns.
 *
 * **Unassigned is the number this card exists for.** An overdue task at least has somebody to ask; an
 * open task with neither a staff assignee nor a collaborator is work that will be late and nobody has
 * noticed yet. `chk_tasks_assignee` guarantees a task carries at most one of the two columns, so
 * "unowned" is precisely both being NULL — there is no third place an owner could hide.
 *
 * **"Open" is `TaskStatus::isOpen()`** — not completed, not cancelled (golden rule 8). Blocked is
 * counted separately because it is open work that is *deliberately* stopped: it inflates the backlog
 * without being anybody's next action, and a manager reading "forty open" wants to know how many of
 * them are waiting on somebody else.
 *
 * **Tasks are counted only inside a live project.** Archiving or cancelling a project does not touch
 * its tasks — `projects.deleted_at` is a soft delete and `tasks` is cascade-deleted only on a hard one
 * — so without the join a cancelled project's leftover `todo` rows would sit in this total for ever and
 * the number would never reach zero. The join is part of the same single query, not a second one.
 *
 * **Subtasks are counted.** A subtask is a real piece of work with its own assignee and its own due
 * date (INV-P12 keeps them exactly one level deep), so excluding them would understate the load by
 * whatever proportion of the board happens to be broken down.
 *
 * A state, not a period: the dashboard's range is ignored for the same reason
 * {@see \App\Dashboard\Widgets\Institute\ActiveCoursesWidget} gives. `due_date` is a DATE column and is
 * compared to a date string — no `whereDate()`, which would cost the `(due_date, status)` index.
 */
final class TaskLoadWidget extends Widget
{
    public function key(): string
    {
        return 'delivery_task_load';
    }

    public function title(): string
    {
        return 'Open tasks';
    }

    public function icon(): string
    {
        return 'check-circle';
    }

    public function permission(): ?string
    {
        return 'tasks.view_any';
    }

    public function module(): ?string
    {
        return 'tasks';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.tasks.index');
    }

    public function emptyMessage(): ?string
    {
        return 'The board is clear.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $openTasks = array_map(
                static fn (TaskStatus $status): string => $status->value,
                array_filter(
                    TaskStatus::cases(),
                    static fn (TaskStatus $status): bool => $status->isOpen(),
                ),
            );

            $openProjects = array_map(
                static fn (ProjectStatus $status): string => $status->value,
                ProjectStatus::open(),
            );

            $today = CarbonImmutable::now(Format::timezone())->toDateString();

            // **One query.** Five figures, one pass over the open rows of live projects. Every column
            // is qualified because `projects` carries a `start_date` and a `status` of its own.
            $row = Task::query()
                ->toBase()
                ->join('projects', static fn (JoinClause $join): JoinClause => $join
                    ->on('projects.id', '=', 'tasks.project_id')
                    ->whereNull('projects.deleted_at')
                    ->whereIn('projects.status', $openProjects))
                ->whereIn('tasks.status', $openTasks)
                ->selectRaw(
                    'COUNT(*) as open_total,'
                    .' SUM(CASE WHEN tasks.due_date IS NOT NULL AND tasks.due_date < ? THEN 1 ELSE 0 END) as overdue,'
                    .' SUM(CASE WHEN tasks.due_date = ? THEN 1 ELSE 0 END) as due_today,'
                    .' SUM(CASE WHEN tasks.assigned_user_id IS NULL AND tasks.assigned_collaborator_id IS NULL'
                    .' THEN 1 ELSE 0 END) as unassigned,'
                    .' SUM(CASE WHEN tasks.status = ? THEN 1 ELSE 0 END) as blocked',
                    [$today, $today, TaskStatus::Blocked->value],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'open' => (int) ($row?->open_total ?? 0),
            'overdue' => (int) ($row?->overdue ?? 0),
            'due_today' => (int) ($row?->due_today ?? 0),
            'unassigned' => (int) ($row?->unassigned ?? 0),
            'blocked' => (int) ($row?->blocked ?? 0),
        ];
    }
}
