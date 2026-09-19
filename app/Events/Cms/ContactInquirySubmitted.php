<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Models\Cms\ContactInquiry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A genuine (non-spam) public contact inquiry was stored (phase-04 §10.1).
 *
 * Fired by `ContactInquiryService::submit()` once the row has committed — never for spam, so a bot's
 * submission is stored but routes nowhere and notifies no one. Phase 4 owns this event (F-2.1).
 *
 * Listeners: `RouteContactInquiry` (dispatches the queued routing job when `website.inquiry_auto_route`
 * is on) and `NotifyStaffOfInquiry` (queued).
 */
final class ContactInquirySubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ContactInquiry $inquiry,
    ) {}
}
