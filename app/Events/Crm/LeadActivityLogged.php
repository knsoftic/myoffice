<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadActivity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A manual timeline entry was logged on a lead (phase-05 §6.1 `recordActivity()`, §10.1).
 * Listener: `TouchLeadActivityCaches`, a no-op whenever the service already stamped the caches.
 */
final class LeadActivityLogged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadActivity $activity,
    ) {}
}
