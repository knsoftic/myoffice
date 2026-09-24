<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Project\Task;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Tasks (phase-19-23 6.23).
 *
 * **Scoped through the project, not through the task.** A task is visible to whoever may see its
 * project plus whoever it is assigned to - and the project half is `Project::visibleTo()` again, so
 * the membership rule stays in one place. A task-level scope would have to repeat that rule and
 * would drift from it.
 */
final class TaskSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Task;
    }

    /**
     * Title only.
     *
     * 6.23 lists `task_code` as searchable, but `tasks` has no such column - a task is identified
     * by its project and its title, and there is no document number to paste. Declaring a column
     * that does not exist would have been a fatal the first time somebody searched.
     */
    public function columns(): array
    {
        return ['tasks.title'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Task::query()
            ->with('project:id,name')
            ->select(['id', 'title', 'status', 'project_id', 'assigned_user_id']);

        if (! $this->can($viewer, 'view_any')) {
            $query->where(static function ($q) use ($viewer): void {
                $q->where('tasks.assigned_user_id', $viewer->getKey())
                    ->orWhereHas('project', static fn ($p) => $p->visibleTo($viewer));
            });
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Task $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->title,
            subtitle: $model->project?->name,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: [],
            url: $this->urlFor('admin.tasks.show', [$model->getKey()], $viewer),
        );
    }
}
