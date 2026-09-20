<?php

declare(strict_types=1);

namespace App\Support\Portal\Sections;

use App\Contracts\Portal\ClientPortalRecordSection;
use App\Enums\ProjectStatus;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The client's own projects (phase-06 §7.7, §8.11, §9's Client row).
 *
 * **{@see COLUMNS} is the security boundary, and it is deliberately an allow-list.** A client must never
 * learn the referral, the margin or the effort, so `budget_amount`, `project_value`, `discount_amount`,
 * `net_value`, every `commission_*`, `collaborator_id`, `referral_code`, `estimated_minutes`,
 * `actual_seconds` and every hour column are simply **not selected** — absent from the response, not
 * hidden in a template where the next refactor could reveal them.
 *
 * Rows come from the `Client` that `ClientContext` resolved, never from the request, and
 * {@see find()} returns null for anything else so another client's id is a **404** rather than a 403 that
 * would confirm the project exists.
 *
 * Read-only is structural: this section serves lists and detail, and the panel registers no write route.
 */
final class ProjectsSection implements ClientPortalRecordSection
{
    /** Everything a client may see of a project — see the class docblock for what is missing and why. */
    public const COLUMNS = [
        'id', 'code', 'name', 'client_id', 'description', 'project_type', 'priority', 'status',
        'start_date', 'deadline', 'completed_on', 'progress_percent', 'created_at',
    ];

    public function key(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'My Projects';
    }

    public function icon(): string
    {
        return 'folder';
    }

    public function module(): ?string
    {
        return 'projects';
    }

    public function permission(): string
    {
        return 'client_portal.projects';
    }

    public function sort(): int
    {
        return 10;
    }

    public function badgeCount(Client $client): ?int
    {
        return $this->query($client)
            ->whereNotIn('status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value])
            ->count();
    }

    public function paginate(Client $client, array $filters): LengthAwarePaginator
    {
        return $this->query($client)
            ->when(
                ($filters['status'] ?? null) !== null,
                fn (Builder $query) => $query->where('status', $filters['status'])
            )
            ->orderByDesc('created_at')
            ->paginate(per_page())
            ->withQueryString();
    }

    public function find(Client $client, User $user, int $id): ?Model
    {
        return $this->query($client)->whereKey($id)->first();
    }

    public function view(): string
    {
        return 'client.projects.index';
    }

    public function detailView(): string
    {
        return 'client.projects.show';
    }

    /**
     * @return Builder<Project>
     */
    private function query(Client $client): Builder
    {
        return Project::query()
            ->select(self::COLUMNS)
            ->where('client_id', $client->getKey());
    }
}
