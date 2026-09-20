<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\Task;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `tasks.actual_seconds` and `projects.actual_seconds` (phase-06 §6.1, INV-P6).
 *
 * **Recompute, never increment.** Each method runs one `UPDATE ... SET actual_seconds = (SELECT
 * COALESCE(SUM(duration_seconds), 0) FROM time_entries WHERE ... AND deleted_at IS NULL)` while holding the
 * row's lock. Two things follow, and both are the point:
 *
 *   · a cache that drifted repairs itself the next time anything touches it, so a crashed request or a
 *     half-applied change leaves no permanent wrong number;
 *   · a job that runs twice writes the same value twice, so a replay cannot inflate anything — which an
 *     `increment()` could not promise.
 *
 * A discarded (soft-deleted) entry leaves the SUM immediately, which is what makes "discard and re-enter"
 * a complete correction rather than a correction plus a cleanup.
 *
 * `projects.actual_seconds` is summed from the **project's own entries**, not from its tasks: time logged
 * against a project with no task (a meeting, setup) is real time and belongs in the project total.
 *
 * `actual_minutes` and `actual_hours` follow automatically — they are STORED generated columns over
 * `actual_seconds` (INV-P7), so nothing here writes them and nothing can.
 */
final class TimeRollupService
{
    /**
     * Recompute one task's cached seconds. Returns the new total.
     */
    public function syncTask(Task $task): int
    {
        return $this->sync('tasks', 'task_id', (int) $task->getKey(), $task);
    }

    /**
     * Recompute one project's cached seconds. Returns the new total.
     */
    public function syncProject(Project $project): int
    {
        return $this->sync('projects', 'project_id', (int) $project->getKey(), $project);
    }

    /**
     * Recompute the task (when there is one) and then its project, in the fixed lock order of §6 —
     * project -> milestone -> task, taken here as task then project because the caller already holds the
     * project row in every path that reaches this method.
     */
    public function syncFor(Project $project, ?Task $task = null): void
    {
        if ($task !== null) {
            $this->syncTask($task);
        }

        $this->syncProject($project);
    }

    private function sync(string $table, string $foreignKey, int $id, Project|Task $model): int
    {
        DB::table($table)->where('id', $id)->lockForUpdate()->value('id');

        DB::statement(
            "UPDATE `{$table}` SET `actual_seconds` = ("
            .'SELECT COALESCE(SUM(`duration_seconds`), 0) FROM `time_entries`'
            ." WHERE `time_entries`.`{$foreignKey}` = ? AND `time_entries`.`deleted_at` IS NULL"
            .') WHERE `id` = ?',
            [$id, $id]
        );

        $total = (int) DB::table($table)->where('id', $id)->value('actual_seconds');

        // Keep the in-memory model honest, including the two generated columns that follow the seconds.
        if ($model->exists) {
            $model->refresh();
        }

        return $total;
    }
}
