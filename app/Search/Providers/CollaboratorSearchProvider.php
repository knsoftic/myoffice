<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Collaborator\Collaborator;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Collaborators (phase-19-23 6.23).
 *
 * **A collaborator sees only themselves**, which is the contract's wording and is stricter than it
 * sounds: not "their own referrals", not "collaborators at their agency" - one row. Anything wider
 * would let a partner enumerate the others, and a referral network is a commercial relationship
 * each side is entitled to keep to itself.
 */
final class CollaboratorSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Collaborator;
    }

    public function columns(): array
    {
        return [
            'collaborators.name',
            'collaborators.company_name',
            'collaborators.collaborator_code',
            'collaborators.referral_code',
            'collaborators.email',
            'collaborators.phone',
        ];
    }

    public function exactColumns(): array
    {
        return ['collaborator_code', 'referral_code'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Collaborator::query()->select([
            'id', 'name', 'company_name', 'collaborator_code', 'referral_code', 'email', 'phone', 'status', 'user_id',
        ]);

        if (! $this->can($viewer, 'view_any')) {
            $own = Collaborator::query()->where('user_id', $viewer->getKey())->value('id');

            if ($own === null) {
                return collect();
            }

            $query->whereKey($own);
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Collaborator $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->company_name ?: $model->collaborator_code,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Code' => $model->collaborator_code, 'Referral code' => $model->referral_code],
            url: $this->urlFor('admin.collaborators.show', [$model->getKey()], $viewer),
        );
    }
}
