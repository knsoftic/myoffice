<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadFollowUp;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A follow-up was completed with an outcome (phase-05 §6.3 `complete()`, §10.1); `next` is the successor
 * created in the same transaction, when one was.
 */
final class LeadFollowUpCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadFollowUp $followUp,
        public readonly ?LeadFollowUp $next = null,
    ) {}
}
