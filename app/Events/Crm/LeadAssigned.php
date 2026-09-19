<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead changed owner (phase-05 §6.1 `assign()` / `bulkAssign()`, §10.1). `toUserId` null means unassigned.
 * Listener: `NotifyLeadAssignee` — the new assignee only, never the old one, and never the actor assigning
 * a lead to themselves.
 */
final class LeadAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?int $fromUserId,
        public readonly ?int $toUserId,
        public readonly ?int $actorId = null,
        public readonly ?string $reason = null,
    ) {}
}
