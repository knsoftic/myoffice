<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\JobApplicationStatusChanged;
use App\Services\Cms\CmsAuditor;

/**
 * Write the activity entry of a pipeline move (phase-04 §6.8 `changeStatus()`, §10.1, §10.5 "application
 * stage change"): the old and new stage, the reason and the interview slot.
 *
 * `JobApplicationService::changeStatus()` saves the stage without a generic "updated" row, so this entry is
 * the **one** record of the move — and the source the application detail screen builds its pipeline
 * timeline from (who moved the candidate, when, why). Synchronous, right after commit, so it carries the
 * request's actor, IP and device.
 */
final class LogApplicationStage
{
    public function __construct(
        private readonly CmsAuditor $auditor,
    ) {}

    public function handle(JobApplicationStatusChanged $event): void
    {
        $old = ['status' => $event->from->value];
        $new = ['status' => $event->to->value];

        foreach (['interview_at', 'interview_mode', 'interview_location'] as $key) {
            if (array_key_exists($key, $event->interview)) {
                $new[$key] = $event->interview[$key];
            }
        }

        if ($event->reason !== null && $event->reason !== '' && $event->to->requiresReason()) {
            $new['rejection_reason'] = $event->reason;
        }

        $this->auditor->record(
            module: 'job_applications',
            description: sprintf('Application moved from %s to %s', $event->from->label(), $event->to->label()),
            subject: $event->application,
            properties: ['old' => $old, 'attributes' => $new],
            reason: $event->reason,
            event: 'status_changed',
        );
    }
}
