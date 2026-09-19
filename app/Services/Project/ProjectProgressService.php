<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\MilestoneStatus;
use App\Enums\ProgressBasis;
use App\Enums\ProgressMode;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Events\Project\MilestoneProgressChanged;
use App\Events\Project\ProjectProgressChanged;
use App\Events\Project\TaskProgressChanged;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\Project\Task;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of every `progress_percent` in the system (phase-06 §6.3, INV-P8).
 *
 * Nothing else may write the column: `Project`, `ProjectMilestone` and `Task` all refuse a dirty
 * `progress_percent` unless this service brackets the write with `unlock()`. That is the whole point of
 * [D-P6-3] — a percentage that appears on a client's screen has exactly one derivation, and a manual
 * override is a reasoned, attributed, revocable act rather than a number somebody typed into an edit form.
 *
 * **The algorithm, four levels, bottom-up** (§6.3). Every division goes through `App\Support\Money`
 * (bcmath), every result is rounded half-up to 4 decimals and clamped to `0 .. 100`, and a **cancelled row
 * is excluded from its parent's denominator, never counted as zero** (INV-P9) — the difference between
 * "we cancelled half the work" reading as 100 % of what remains and as 50 % of what was planned.
 *
 *   1. **Leaf task** — completed is 100; otherwise, with a checklist,
 *      `max(status weight, 100 × done / total)`. The `max` keeps both signals honest in both directions: a
 *      task in review with 1 of 10 ticks is 75 % because review implies the work is done, and a task in
 *      progress with 9 of 10 ticks is 90 % rather than 25 %.
 *   2. **Parent task** — the weighted average of its non-cancelled subtasks, weighted by
 *      `estimated_minutes` falling back to `projects.default_task_estimate_minutes`. A parent's own
 *      checklist is ignored while subtasks exist: one rule wins, and it is written down here so two
 *      developers cannot each pick a different one.
 *   3. **Milestone** — the weighted average of its non-cancelled **depth-0** tasks (a subtask is already
 *      inside its parent's figure); with none, the status weight; completed is always 100.
 *   4. **Project** — by `progress_basis`, falling back to the other basis and then to the status weight;
 *      completed is forced to 100 and cancelled is frozen at its last value.
 *
 * **The cascade** runs inside the caller's transaction, walks task -> parent -> milestone -> project,
 * writes only rows whose value actually changed, and fires one event per changed row after commit. It is
 * guarded by a per-request set of visited rows, so a task that is somehow its own ancestor cannot spin.
 */
final class ProjectProgressService
{
    private const MIN = '0';

    private const MAX = '100';

    /** Rows already recalculated in this request, as `type:id` — the recursion guard of §6.1. */
    private array $visited = [];

    /**
     * Recalculate one task, then cascade upwards. Returns whether this task's own value changed.
     */
    public function recalculateTask(Task $task, bool $cascade = true): bool
    {
        $key = 'task:'.$task->getKey();

        if (isset($this->visited[$key])) {
            return false;
        }

        $this->visited[$key] = true;

        $from = (string) $task->progress_percent;
        $changed = $this->write($task, $this->deriveTask($task));

        if ($changed) {
            TaskProgressChanged::dispatch($task, $from, (string) $task->progress_percent);
        }

        if (! $cascade) {
            return $changed;
        }

        $parent = $task->parent;

        if ($parent !== null) {
            $this->recalculateTask($parent);
        }

        $milestone = $task->milestone;

        if ($milestone !== null) {
            $this->recalculateMilestone($milestone);
        }

        $project = $task->project;

        if ($project !== null) {
            $this->recalculateProject($project);
        }

        return $changed;
    }

    /**
     * Recalculate one milestone, then its project.
     */
    public function recalculateMilestone(ProjectMilestone $milestone, bool $cascade = true): bool
    {
        $key = 'milestone:'.$milestone->getKey();

        if (isset($this->visited[$key])) {
            return false;
        }

        $this->visited[$key] = true;

        $from = (string) $milestone->progress_percent;
        $changed = $this->write($milestone, $this->deriveMilestone($milestone));

        if ($changed) {
            MilestoneProgressChanged::dispatch($milestone, $from, (string) $milestone->progress_percent);
        }

        if ($cascade && $milestone->project !== null) {
            $this->recalculateProject($milestone->project);
        }

        return $changed;
    }

    /**
     * Recalculate one project. A project in `manual` mode is left alone — {@see derivedFor()} still answers
     * so the screen can show the derived figure beside the override, but nothing is stored.
     */
    public function recalculateProject(Project $project): bool
    {
        $key = 'project:'.$project->getKey();

        if (isset($this->visited[$key])) {
            return false;
        }

        $this->visited[$key] = true;

        if ($project->progress_mode === ProgressMode::Manual) {
            return false;
        }

        $from = (string) $project->progress_percent;
        $changed = $this->write($project, $this->deriveProject($project));

        if ($changed) {
            ProjectProgressChanged::dispatch($project, $from, (string) $project->progress_percent);
        }

        return $changed;
    }

    /**
     * What the algorithm says a project's progress is, whatever mode it is in — the figure a `manual`
     * project shows beside its override so the gap is always visible.
     */
    public function derivedFor(Project $project): string
    {
        return $this->deriveProject($project);
    }

    /**
     * Switch a project to a human-set figure ([D-P6-3]).
     *
     * Refused outright when `projects.progress_manual_override_enabled` is off, because a business that
     * turned the override off means progress to be derived, not merely discouraged.
     */
    public function setManual(Project $project, string $percent, string $reason, ?User $actor = null): Project
    {
        if (! (bool) setting('projects.progress_manual_override_enabled', true)) {
            throw ProjectRuleException::manualProgressDisabled();
        }

        if (trim($reason) === '') {
            throw ProjectRuleException::reasonRequired('progress_reason', 'Say why the derived figure is wrong.');
        }

        $percent = Money::clamp($percent, self::MIN, self::MAX);

        return DB::transaction(function () use ($project, $percent, $reason, $actor): Project {
            $from = (string) $project->progress_percent;

            Project::unlock(Project::GROUP_PROGRESS, function () use ($project, $percent, $reason, $actor): void {
                $project->forceFill([
                    'progress_percent' => $percent,
                    'progress_mode' => ProgressMode::Manual->value,
                    'progress_reason' => $reason,
                    'progress_set_by' => $actor?->getKey(),
                    'progress_updated_at' => now(),
                ])->save();
            });

            ProjectProgressChanged::dispatch($project, $from, $percent, true, $actor?->getKey());

            return $project->refresh();
        });
    }

    /**
     * Return a project to derivation and recalculate immediately, so the screen never shows a stale
     * override after the switch back.
     */
    public function setAuto(Project $project, string $reason, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $reason, $actor): Project {
            Project::unlock(Project::GROUP_PROGRESS, function () use ($project, $reason, $actor): void {
                $project->forceFill([
                    'progress_mode' => ProgressMode::Auto->value,
                    'progress_reason' => null,
                    'progress_set_by' => $actor?->getKey(),
                    'progress_updated_at' => now(),
                ])->save();
            });

            $this->forget($project);
            $this->recalculateProject($project);

            return $project->refresh();
        });
    }

    /**
     * Drop the recursion guard — used by a scheduled sweep that walks many projects in one process.
     */
    public function forget(?Project $project = null): void
    {
        if ($project === null) {
            $this->visited = [];

            return;
        }

        unset($this->visited['project:'.$project->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | §6.3 — the four levels
    |--------------------------------------------------------------------------
    */

    private function deriveTask(Task $task): string
    {
        if ($task->status === TaskStatus::Completed) {
            return self::MAX;
        }

        $subtasks = $task->subtasks()
            ->where('status', '<>', TaskStatus::Cancelled->value)
            ->get(['id', 'status', 'estimated_minutes', 'progress_percent', 'checklist_total', 'checklist_done']);

        // Level 2 — a parent task is the weighted average of its subtasks, and its own checklist is
        // deliberately ignored while any subtask exists.
        if ($subtasks->isNotEmpty()) {
            $average = Money::weightedAverage($subtasks->map(fn (Task $subtask): array => [
                (string) $subtask->progress_percent,
                $this->taskWeight($subtask),
            ]));

            return $this->clamp($average ?? $this->statusWeight($task));
        }

        // Level 1 — a leaf task.
        $weight = $this->statusWeight($task);
        $total = (int) $task->checklist_total;

        if ($total > 0) {
            // 100 * done / total, at the 4 decimals the column holds. Safe from the zero-denominator
            // refusal because this branch only runs when the task has a checklist.
            $ticked = Money::percentageOf((string) (int) $task->checklist_done, (string) $total);

            // max(), not "whichever is newer": a task in review with 1 of 10 ticks is still 75 %, and one
            // in progress with 9 of 10 is 90 % rather than 25 %.
            return $this->clamp(Money::compare($ticked, $weight) > 0 ? $ticked : $weight);
        }

        return $this->clamp($weight);
    }

    private function deriveMilestone(ProjectMilestone $milestone): string
    {
        if ($milestone->status === MilestoneStatus::Completed) {
            return self::MAX;
        }

        $tasks = $milestone->tasks()
            ->where('depth', 0)
            ->where('status', '<>', TaskStatus::Cancelled->value)
            ->get(['id', 'estimated_minutes', 'progress_percent']);

        $average = Money::weightedAverage($tasks->map(fn (Task $task): array => [
            (string) $task->progress_percent,
            $this->taskWeight($task),
        ]));

        if ($average !== null) {
            return $this->clamp($average);
        }

        return $this->clamp($milestone->status->progressWeight() ?? self::MIN);
    }

    private function deriveProject(Project $project): string
    {
        if ($project->status === ProjectStatus::Completed) {
            return self::MAX;
        }

        // A cancelled project is frozen at whatever it had reached — the work stopped, it did not undo.
        if ($project->status === ProjectStatus::Cancelled) {
            return $this->clamp((string) $project->progress_percent);
        }

        $byMilestones = fn (): ?string => Money::weightedAverage(
            $project->milestones()
                ->where('status', '<>', MilestoneStatus::Cancelled->value)
                ->get(['id', 'weight', 'progress_percent'])
                ->map(fn (ProjectMilestone $milestone): array => [
                    (string) $milestone->progress_percent,
                    (string) $milestone->weight,
                ])
        );

        $byTasks = fn (): ?string => Money::weightedAverage(
            $project->tasks()
                ->where('depth', 0)
                ->where('status', '<>', TaskStatus::Cancelled->value)
                ->get(['id', 'estimated_minutes', 'progress_percent'])
                ->map(fn (Task $task): array => [
                    (string) $task->progress_percent,
                    $this->taskWeight($task),
                ])
        );

        $primary = $project->progress_basis === ProgressBasis::Milestones ? $byMilestones : $byTasks;
        $fallback = $project->progress_basis === ProgressBasis::Milestones ? $byTasks : $byMilestones;

        $derived = $primary() ?? $fallback() ?? $project->status->progressWeight();

        return $this->clamp($derived);
    }

    /**
     * §6.3's weight rule: a task's own estimate, or the documented default for one nobody estimated.
     */
    private function taskWeight(Task $task): string
    {
        $minutes = $task->estimated_minutes;

        if ($minutes === null || $minutes <= 0) {
            $minutes = (int) setting('projects.default_task_estimate_minutes', 60);
        }

        return (string) max($minutes, 1);
    }

    private function statusWeight(Task $task): string
    {
        return $task->status->progressWeight() ?? self::MIN;
    }

    private function clamp(string $value): string
    {
        return Money::clamp($value, self::MIN, self::MAX);
    }

    /**
     * Write the derived figure if it moved, with the guard opened for exactly that write.
     */
    private function write(Project|ProjectMilestone|Task $model, string $percent): bool
    {
        if (Money::compare((string) $model->progress_percent, $percent) === 0) {
            return false;
        }

        $model::unlock(Project::GROUP_PROGRESS, static function () use ($model, $percent): void {
            $model->forceFill(['progress_percent' => $percent])->save();
        });

        return true;
    }
}
