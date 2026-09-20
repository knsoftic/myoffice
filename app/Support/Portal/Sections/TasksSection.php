<?php

declare(strict_types=1);

namespace App\Support\Portal\Sections;

use App\Contracts\Portal\ClientPortalSection;
use App\Enums\TaskStatus;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\Project\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client's read-only task list (phase-06 §7.7, §9, F-3.1).
 *
 * **Two switches, both required**: the global `projects.client_can_see_tasks` and the per-row
 * `tasks.is_client_visible`. Without the per-row flag a business that turned the section on would expose
 * every internal task the moment it did; without the global one, a business could not close the section
 * at all. When the global switch is off {@see badgeCount()} and {@see paginate()} answer empty, and
 * `TaskPolicy::viewByClient()` refuses the detail route — the query and the policy agree.
 *
 * `estimated_hours`, `actual_hours`, `actual_seconds` and `estimated_minutes` are **not selected**: how
 * long the work took is the software house's business, not the client's.
 */
final class TasksSection implements ClientPortalSection
{
    /** No hours, no effort, no internal assignee id. */
    public const COLUMNS = [
        'id', 'project_id', 'project_milestone_id', 'title', 'description',
        'priority', 'status', 'due_date', 'progress_percent', 'completed_at',
    ];

    public function key(): string
    {
        return 'tasks';
    }

    public function label(): string
    {
        return 'Tasks';
    }

    public function icon(): string
    {
        return 'check-circle';
    }

    public function module(): ?string
    {
        return 'tasks';
    }

    public function permission(): string
    {
        return 'client_portal.tasks';
    }

    public function sort(): int
    {
        return 30;
    }

    public function badgeCount(Client $client): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->query($client)
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->count();
    }

    public function paginate(Client $client, array $filters): LengthAwarePaginator
    {
        return $this->query($client)
            ->when(
                ($filters['project'] ?? null) !== null,
                fn (Builder $query) => $query->where('project_id', (int) $filters['project'])
            )
            ->with('project:id,code,name')
            ->orderByDesc('due_date')
            ->paginate(per_page())
            ->withQueryString();
    }

    public function view(): string
    {
        return 'client.tasks.index';
    }

    /**
     * @return Builder<Task>
     */
    private function query(Client $client): Builder
    {
        $query = Task::query()
            ->select(self::COLUMNS)
            ->whereIn('project_id', Project::query()->where('client_id', $client->getKey())->select('id'))
            ->where('is_client_visible', true);

        // The global switch is a short-circuit, not a predicate: off means the section shows nothing.
        return $this->enabled() ? $query : $query->whereRaw('1 = 0');
    }

    private function enabled(): bool
    {
        return (bool) setting('projects.client_can_see_tasks', true);
    }
}
