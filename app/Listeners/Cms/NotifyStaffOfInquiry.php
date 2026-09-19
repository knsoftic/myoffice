<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\ContactInquirySubmitted;
use App\Listeners\Cms\Concerns\NotifiesStaff;
use App\Models\Cms\ContactInquiry;
use App\Notifications\Cms\NewContactInquiryNotification;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the inquiry queue about a new genuine inquiry (phase-04 §10.1, §10.2) — queued.
 *
 * Recipients: active users holding `contact_inquiries.view_any`, plus `website.contact_notify_emails`
 * (mail only). Re-reads the inquiry first: one marked spam or deleted before the worker ran is not
 * announced. Nothing is sent while the `contact_inquiries` module is switched off.
 */
final class NotifyStaffOfInquiry implements ShouldQueue
{
    use NotifiesStaff;

    public bool $deleteWhenMissingModels = true;

    public function handle(ContactInquirySubmitted $event): void
    {
        if (! Modules::enabled('contact_inquiries')) {
            return;
        }

        $inquiry = ContactInquiry::query()->find($event->inquiry->getKey());

        if (! $inquiry instanceof ContactInquiry || (bool) $inquiry->getAttribute('is_spam')) {
            return;
        }

        $this->deliver(
            $this->usersHolding('contact_inquiries.view_any'),
            $this->addressesFrom('website.contact_notify_emails'),
            new NewContactInquiryNotification($inquiry),
        );
    }
}
