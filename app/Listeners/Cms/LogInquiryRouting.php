<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\ContactInquiryRouted;
use App\Services\Cms\CmsAuditor;

/**
 * Write the activity entry that names both records of a successful routing (phase-04 §6.10.2 step 6,
 * §10.1, §10.5 "inquiry routed").
 *
 * Runs synchronously right after the routing transaction commits, so the entry carries the actor, IP and
 * device of the request that clicked **Route now** (none from the console or a queue worker) and is never
 * written for a claim that rolled back. Failures are logged by `InquiryRouter` itself.
 */
final class LogInquiryRouting
{
    public function __construct(
        private readonly CmsAuditor $auditor,
    ) {}

    public function handle(ContactInquiryRouted $event): void
    {
        $inquiry = $event->inquiry;
        $record = $event->record;
        $target = (string) $inquiry->getAttribute('routing_target');

        $this->auditor->record(
            module: 'contact_inquiries',
            description: sprintf(
                'Contact inquiry #%d routed to %s #%s',
                (int) $inquiry->getKey(),
                class_basename($record),
                (string) $record->getKey(),
            ),
            subject: $inquiry,
            properties: [
                'target' => $target,
                'contact_inquiry_id' => (int) $inquiry->getKey(),
                'routed_type' => $record::class,
                'routed_id' => $record->getKey(),
                'manual' => $event->manual,
                'old' => ['routing_status' => 'pending'],
                'attributes' => ['routing_status' => 'routed', 'routed_type' => $record::class, 'routed_id' => $record->getKey()],
            ],
            event: 'routed',
        );
    }
}
