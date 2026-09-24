<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Project\Project;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Projects (phase-19-23 6.23).
 *
 * Scoped by `Project::visibleTo()` - Phase 6's membership rule. A client on the portal reaches
 * their own projects through the same scope, because that scope already knows about the client
 * relationship; there is no second branch here for the portal case, and there should not be.
 *
 * **The client name is searchable, which needs a join.** The join is `leftJoin`, not `join`: a
 * project with no client is still a project, and an inner join would silently drop it from every
 * search.
 */
final class ProjectSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Project;
    }

    public function columns(): array
    {
        return ['projects.name', 'projects.code', 'project_client.name'];
    }

    public function exactColumns(): array
    {
        return ['code'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Project::query()
            ->visibleTo($viewer)
            ->leftJoin('clients as project_client', 'project_client.id', '=', 'projects.client_id')
            ->select(['projects.id', 'projects.name', 'projects.code', 'projects.status', 'projects.client_id'])
            ->addSelect('project_client.name as client_name');

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Project $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->getAttribute('client_name'),
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Code' => $model->code],
            url: $this->urlFor('admin.projects.show', [$model->getKey()], $viewer),
        );
    }
}
