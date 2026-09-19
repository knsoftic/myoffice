<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadFollowUp;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A pending follow-up now exists on a lead — scheduled, rescheduled, or the successor of a completion
 * (phase-05 §6.3, §10.1). `previous` is the row it replaces, when there is one.
 */
final class LeadFollowUpScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadFollowUp $followUp,
        public readonly ?LeadFollowUp $previous = null,
    ) {}
}
