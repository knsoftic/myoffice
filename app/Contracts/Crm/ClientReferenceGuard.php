<?php

declare(strict_types=1);

namespace App\Contracts\Crm;

use App\Models\Crm\Client;

/**
 * "Does anything still reference this client?" — answered by the phase that owns the referencing table
 * (phase-05 §6.7 `delete()`, D28).
 *
 * A soft delete of a client is refused while an undeleted project (Phase 6), invoice (Phase 13) or payment
 * (the spine) points at it. Each of those phases tags an implementation `crm.client_references` in its service
 * provider; Phase 5 queries no table it does not own.
 */
interface ClientReferenceGuard
{
    public const TAG = 'crm.client_references';

    /**
     * A human sentence naming what still references the client ("2 open projects"), or null when nothing does.
     */
    public function blockingReferences(Client $client): ?string;
}
