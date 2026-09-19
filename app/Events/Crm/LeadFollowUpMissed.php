<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadFollowUp;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `crm:follow-ups-mark-missed` moved a pending follow-up past its grace window to `missed` (phase-05 §6.3
 * `markMissed()`, §10.5). Dispatched once per follow-up, because only a `pending` row can move.
 * Listener: `NotifyAssigneeOfMissedFollowUp` — one `LeadFollowUpOverdue` to the assignee.
 */
final class LeadFollowUpMissed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadFollowUp $followUp,
    ) {}
}
