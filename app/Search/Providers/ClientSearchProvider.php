<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Crm\Client;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Clients (phase-19-23 6.23).
 *
 * **Scope: the account-manager rule Phase 5 9 already applies.** Somebody holding
 * `clients.view_any` sees every client; somebody holding only `clients.view` sees the ones they
 * manage. A client on the portal sees their own row and nothing else - which is why the portal case
 * is a `where` on the id rather than an absence of scope.
 */
final class ClientSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Client;
    }

    public function columns(): array
    {
        return ['clients.name', 'clients.company_name', 'clients.client_code', 'clients.email', 'clients.phone'];
    }

    public function exactColumns(): array
    {
        return ['client_code'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Client::query()->select(['id', 'name', 'company_name', 'client_code', 'email', 'phone', 'status', 'account_manager_id']);

        if (! $this->can($viewer, 'view_any')) {
            // The narrow grant: only what this person manages. A client-portal user reaches their
            // own row through `ClientContext`, which sets exactly this.
            $query->where('clients.account_manager_id', $viewer->getKey());
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Client $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->company_name ?: $model->email,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Code' => $model->client_code, 'Phone' => $model->phone],
            url: $this->urlFor('admin.clients.show', [$model->getKey()], $viewer),
        );
    }
}
