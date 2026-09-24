<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Crm\Lead;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Leads (phase-19-23 6.23).
 *
 * Scoped by `Lead::visibleTo()` - [D-P5-8]'s rule, where `view_any` is the whole pipeline and
 * `view` is your own. Reused rather than restated.
 */
final class LeadSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Lead;
    }

    public function columns(): array
    {
        return ['leads.name', 'leads.company', 'leads.phone', 'leads.email', 'leads.lead_no'];
    }

    public function exactColumns(): array
    {
        return ['lead_no'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Lead::query()
            ->visibleTo($viewer)
            ->select(['id', 'lead_no', 'name', 'company', 'phone', 'email', 'status', 'assigned_to']);

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Lead $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->company ?: $model->email,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Lead no' => $model->lead_no, 'Phone' => $model->phone],
            url: $this->urlFor('admin.leads.show', [$model->getKey()], $viewer),
        );
    }
}
