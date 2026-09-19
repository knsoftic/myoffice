<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\ContactInquirySubmitted;
use App\Jobs\Cms\RouteContactInquiry as RouteContactInquiryJob;
use App\Support\SettingsRepository;

/**
 * On `ContactInquirySubmitted`, queue the routing of the new inquiry — when `website.inquiry_auto_route`
 * is on (phase-04 §6.10.5, §10.1).
 *
 * The routing itself runs in the queued `App\Jobs\Cms\RouteContactInquiry` (3 tries, 1/5/15 minute
 * backoff), so this listener only decides and dispatches and never makes the visitor wait. With the
 * setting off, nothing is attempted: the inquiry stays `pending` with no error until someone clicks
 * **Route now** (§6.10.3). Spam never reaches here — the event is not fired for it.
 */
final class RouteContactInquiry
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(ContactInquirySubmitted $event): void
    {
        if ((bool) $event->inquiry->getAttribute('is_spam')) {
            return;
        }

        $enabled = $this->settings->get('website.inquiry_auto_route', true);

        if (! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        RouteContactInquiryJob::dispatch((int) $event->inquiry->getKey());
    }
}
