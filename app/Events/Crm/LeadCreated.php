<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead was created — by hand, from a website inquiry or from an import row (phase-05 §10.1).
 *
 * Dispatched after the creating transaction commits. Listeners: `RecordCapturedReferral` (attach a captured
 * `?ref=` code once a recorder is bound) and `NotifyStaffOfWebsiteLead` (a lead from a contact inquiry).
 */
final class LeadCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?int $actorId = null,
    ) {}
}
