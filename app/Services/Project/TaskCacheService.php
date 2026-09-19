<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\TaskStatus;
use App\Models\Project\Task;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the six counting caches on `tasks` (phase-06 §6.1, INV-P6).
 *
 * `checklist_total` / `checklist_done`, `subtask_total` / `subtask_done`, `comment_count` and
 * `attachment_count` all exist for one reason: to keep the Kanban board free of an N+1 that would run six
 * queries per card. They are **recomputed by COUNT under the task's row lock, never incremented** — so a
 * replayed job writes the same number, and a cache that drifted repairs itself on the next touch.
 *
 * Cancelled subtasks are excluded from `subtask_total` as well as from `subtask_done`, matching INV-P9:
 * a cancelled subtask is not outstanding work, so it must not make a parent look permanently unfinished.
 *
 * Trashed rows are excluded everywhere — deleting a comment or a checklist line takes it out of the count
 * in the same transaction, which is why `chk_tasks_checklist` (`done <= total`) can never be tripped by a
 * half-applied change.
 */
final class TaskCacheService
{
    /**
     * Recompute every count on one task. Returns the task, refreshed.
     */
    public function sync(Task $task): Task
    {
        $id = (int) $task->getKey();

        DB::table('tasks')->where('id', $id)->lockForUpdate()->value('id');

        $checklist = DB::table('task_checklist_items')
            ->where('task_id', $id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(`is_done`), 0) AS done')
            ->first();

        $subtasks = DB::table('tasks')
            ->where('parent_task_id', $id)
            ->whereNull('deleted_at')
            ->where('status', '<>', TaskStatus::Cancelled->value)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(`status` = ?), 0) AS done', [TaskStatus::Completed->value])
            ->first();

        $comments = DB::table('task_comments')
            ->where('task_id', $id)
            ->whereNull('deleted_at')
            ->count();

        $attachments = DB::table('attachments')
            ->where('attachable_type', 'task')
            ->where('attachable_id', $id)
            ->whereNull('deleted_at')
            ->count();

        DB::table('tasks')->where('id', $id)->update([
            'checklist_total' => (int) ($checklist->total ?? 0),
            'checklist_done' => (int) ($checklist->done ?? 0),
            'subtask_total' => (int) ($subtasks->total ?? 0),
            'subtask_done' => (int) ($subtasks->done ?? 0),
            'comment_count' => $comments,
            'attachment_count' => $attachments,
        ]);

        return $task->refresh();
    }

    /**
     * Recompute the task and, when it is a subtask, its parent as well — a subtask being completed or
     * cancelled moves the parent's `subtask_done` / `subtask_total` too.
     */
    public function syncWithParent(Task $task): Task
    {
        $task = $this->sync($task);

        $parent = $task->parent;

        if ($parent !== null) {
            $this->sync($parent);
        }

        return $task;
    }
}
