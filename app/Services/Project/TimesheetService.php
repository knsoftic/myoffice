<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\Project\TimeEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The only read path for hours (phase-06 §6.6, requirement §23).
 *
 * **Every rollup is one GROUP BY.** Seconds are summed in the database and formatted once at the end, so
 * a month of entries costs the same number of queries as a day and no figure is ever built by looping in
 * PHP. That is also why each method returns plain rows rather than models: nothing here needs a model,
 * and hydrating thousands of them to add up a column would be the slowest way to get the same answer.
 *
 * **Everything groups on `work_date`**, never on a timestamp (§2.10). A session from 23:50 to 00:10 is
 * one day's work to the person who did it, and `work_date` is the column that says which day they meant.
 *
 * Scoping is the caller's job: pass the query already narrowed by `time_tracking.view_any` or by the
 * signed-in worker, exactly as `TimeEntryPolicy` decides it.
 */
final readonly class TimesheetService
{
    /**
     * Hours per day across a window — the bar chart behind §8.9.
     *
     * @return Collection<int, array{date: string, seconds: int, hours: string}>
     */
    public function daily(Carbon $from, Carbon $to, ?User $worker = null, ?int $projectId = null): Collection
    {
        return $this->base($worker, $projectId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('`work_date` AS d, COALESCE(SUM(`duration_seconds`), 0) AS seconds')
            ->groupBy('work_date')
            ->orderBy('work_date')
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->d,
                'seconds' => (int) $row->seconds,
                'hours' => $this->hours((int) $row->seconds),
            ]);
    }

    /**
     * The week grid: one row per worker, one column per day, plus a utilisation percentage.
     *
     * Utilisation divides against `projects.working_hours_per_day` × the days in the window, through
     * `Money` — a percentage is arithmetic like any other and does not get a float just because it is
     * displayed rather than paid.
     *
     * @return Collection<int, array{worker: string, seconds: int, hours: string, utilisation: string}>
     */
    public function weekly(Carbon $from, Carbon $to, ?int $projectId = null): Collection
    {
        $days = max(1, $from->diffInDays($to) + 1);
        $perDay = (int) setting('projects.working_hours_per_day', 8);
        $capacity = (string) max(1, $perDay * $days);

        return $this->base(null, $projectId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('users', 'users.id', '=', 'time_entries.user_id')
            ->selectRaw('COALESCE(`users`.`name`, CONCAT("Collaborator #", `time_entries`.`collaborator_id`)) AS worker,'
                .' COALESCE(SUM(`time_entries`.`duration_seconds`), 0) AS seconds')
            ->groupBy('worker')
            ->orderByDesc('seconds')
            ->get()
            ->map(function (object $row) use ($capacity): array {
                $hours = $this->hours((int) $row->seconds);

                return [
                    'worker' => (string) $row->worker,
                    'seconds' => (int) $row->seconds,
                    'hours' => $hours,
                    'utilisation' => Money::percentageOf($hours, $capacity),
                ];
            });
    }

    /**
     * Hours per project across a window.
     *
     * @return Collection<int, array{project: string, code: string, seconds: int, hours: string}>
     */
    public function byProject(Carbon $from, Carbon $to, ?User $worker = null): Collection
    {
        return $this->base($worker, null)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('`projects`.`name` AS name, `projects`.`code` AS code,'
                .' COALESCE(SUM(`time_entries`.`duration_seconds`), 0) AS seconds')
            ->groupBy('projects.id', 'projects.name', 'projects.code')
            ->orderByDesc('seconds')
            ->get()
            ->map(fn (object $row): array => [
                'project' => (string) $row->name,
                'code' => (string) $row->code,
                'seconds' => (int) $row->seconds,
                'hours' => $this->hours((int) $row->seconds),
            ]);
    }

    /**
     * Hours per task inside one project.
     *
     * @return Collection<int, array{task: string, seconds: int, hours: string}>
     */
    public function byTask(int $projectId, Carbon $from, Carbon $to): Collection
    {
        return $this->base(null, $projectId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->selectRaw('COALESCE(`tasks`.`title`, "Project-level time") AS title,'
                .' COALESCE(SUM(`time_entries`.`duration_seconds`), 0) AS seconds')
            ->groupBy('title')
            ->orderByDesc('seconds')
            ->get()
            ->map(fn (object $row): array => [
                'task' => (string) $row->title,
                'seconds' => (int) $row->seconds,
                'hours' => $this->hours((int) $row->seconds),
            ]);
    }

    /**
     * Seconds to a two-decimal hour string — the one place that conversion happens.
     */
    public function hours(int $seconds): string
    {
        return Money::round(Money::div((string) $seconds, '3600'), 2);
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function base(?User $worker, ?int $projectId): Builder
    {
        return TimeEntry::query()
            ->when($worker !== null, fn (Builder $query) => $query->where('time_entries.user_id', $worker->getKey()))
            ->when($projectId !== null, fn (Builder $query) => $query->where('time_entries.project_id', $projectId));
    }
}
