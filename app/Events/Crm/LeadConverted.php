<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadConversion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A won lead was converted into a client (and possibly a project) (phase-05 §6.4 `convert()`, §10.1).
 * Listener: `NotifyOfLeadConversion` — the new client's account manager and the converter.
 */
final class LeadConverted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadConversion $conversion,
        public readonly bool $createdClient,
        public readonly ?int $actorId = null,
    ) {}
}
