<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Enums\LeadStatus;
use App\Models\Crm\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead moved between §2.11 statuses (phase-05 §6.1 `changeStatus()`, §10.1), dispatched after commit.
 */
final class LeadStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly LeadStatus $from,
        public readonly LeadStatus $to,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
