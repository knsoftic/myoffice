<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Models\Cms\ContactInquiry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `InquiryRouter::route()` handed an inquiry to its target module (phase-04 §6.10.2 step 6, §10.1).
 *
 * Carries the inquiry and the record the target created (a Phase 5 lead, a Phase 15 course inquiry).
 * Dispatched after the routing transaction commits, so a listener never sees a claim that rolled back.
 * Listener: `LogInquiryRouting`, which writes the activity entry naming both records.
 */
final class ContactInquiryRouted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ContactInquiry $inquiry,
        public readonly Model $record,
        public readonly bool $manual = false,
    ) {}
}
