<?php

declare(strict_types=1);

namespace App\Contracts\Portal;

use App\Models\Crm\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One screen of the client panel, contributed by the phase that owns its data (phase-05 §1.3 [D-P5-1], D28, D31).
 *
 * Phase 5 registers the sections it owns (documents, notifications, profile); Phase 6 contributes projects,
 * tasks, milestones, progress and files, the spine payments, Phase 13 invoices, Phase 22 tickets, meetings and
 * messages — each into `App\Support\ClientPortalRegistry`, **never** by redeclaring a `client.*` route name.
 *
 * Rules every implementation keeps:
 *
 *   · Every query takes the client from the `Client` argument (resolved by `App\Support\ClientContext`), never
 *     from the request (CLAUDE.md rule 10, phase-05 §9.2).
 *   · `paginate()` selects an explicit column list that omits every withheld column (payments: no
 *     `collaborator_id` / commission state; projects: no internal `budget`; tasks: no hours).
 *   · `badgeCount()` returns null when a count is not meaningful — a card never renders an invented number.
 *   · A section that is not registered has no nav item and its route answers 404.
 */
interface ClientPortalSection
{
    /**
     * Stable snake_case key: `projects`, `tasks`, `milestones`, `progress`, `files`, `documents`, `invoices`,
     * `payments`, `meetings`, `tickets`, `messages`, `notifications`.
     */
    public function key(): string;

    public function label(): string;

    /**
     * An icon name from the house icon set.
     */
    public function icon(): string;

    /**
     * The module slug gating the section, or null for a section no module switch governs.
     */
    public function module(): ?string;

    /**
     * The `client_portal.*` permission a user must hold to see the section.
     */
    public function permission(): string;

    public function sort(): int;

    /**
     * The count shown on the nav item and the dashboard card, or null for none.
     */
    public function badgeCount(Client $client): ?int;

    /**
     * The section's rows for this client only.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(Client $client, array $filters): LengthAwarePaginator;

    /**
     * The Blade view rendering the section (extends `layouts.panel`).
     */
    public function view(): string;
}
