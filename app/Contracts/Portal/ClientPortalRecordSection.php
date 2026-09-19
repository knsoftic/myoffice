<?php

declare(strict_types=1);

namespace App\Contracts\Portal;

use App\Models\Crm\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A client portal section that also serves single records — `client.projects.show`, `client.invoices.show`,
 * `client.tickets.show`, `client.messages.show` (phase-05 §7, §9.2, D28, D31).
 *
 * The client panel controllers detect these methods on a registered section; a section without them answers 404
 * for every detail route, never a fatal. A later phase that serves a detail screen implements this interface.
 *
 * `find()` applies the section's ownership rule for this client and user (a project of this client, a conversation
 * the user participates in) and returns null for anything else, so another client's id is a **404** — ids cannot be
 * probed (resolutions §8 row 5).
 */
interface ClientPortalRecordSection extends ClientPortalSection
{
    public function find(Client $client, User $user, int $id): ?Model;

    /**
     * The Blade view rendering one record (extends `layouts.panel`).
     */
    public function detailView(): string;
}
