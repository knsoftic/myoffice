<?php

declare(strict_types=1);

namespace App\Support\Portal\Sections;

use App\Contracts\Portal\ClientPortalSection;
use App\Enums\MilestoneStatus;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client's milestones (phase-06 §7.7, §8.11, §9).
 *
 * `amount` is selected **only** when the signed-in user holds `client_portal.invoices` (§8.11): a payment
 * schedule is money, and a client who cannot see their invoices has no business seeing the amounts those
 * invoices will carry. The column is added to the SELECT or left out of it — never blanked afterwards.
 *
 * Scoped through the client's own projects, so a milestone of somebody else's project is not reachable.
 */
final class MilestonesSection implements ClientPortalSection
{
    /** What every client may see. */
    public const COLUMNS = [
        'id', 'project_id', 'name', 'description', 'start_date', 'deadline',
        'progress_percent', 'status', 'sort_order', 'completed_on',
    ];

    public function key(): string
    {
        return 'milestones';
    }

    public function label(): string
    {
        return 'Milestones';
    }

    public function icon(): string
    {
        return 'flag';
    }

    public function module(): ?string
    {
        return 'project_milestones';
    }

    public function permission(): string
    {
        return 'client_portal.milestones';
    }

    public function sort(): int
    {
        return 20;
    }

    public function badgeCount(Client $client): ?int
    {
        return $this->query($client)
            ->whereNotIn('status', [MilestoneStatus::Completed->value, MilestoneStatus::Cancelled->value])
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
            ->orderBy('project_id')
            ->orderBy('sort_order')
            ->paginate(per_page())
            ->withQueryString();
    }

    public function view(): string
    {
        return 'client.milestones.index';
    }

    /**
     * @return Builder<ProjectMilestone>
     */
    private function query(Client $client): Builder
    {
        $columns = self::COLUMNS;

        // §8.11: the payment value rides on the invoices permission, not on a milestone ability.
        if (auth()->user()?->can('client_portal.invoices')) {
            $columns[] = 'amount';
        }

        return ProjectMilestone::query()
            ->select($columns)
            ->whereIn('project_id', Project::query()->where('client_id', $client->getKey())->select('id'));
    }
}
