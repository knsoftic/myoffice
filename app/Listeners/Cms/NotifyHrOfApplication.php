<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\JobApplicationReceived;
use App\Listeners\Cms\Concerns\NotifiesStaff;
use App\Models\Cms\JobApplication;
use App\Notifications\Cms\NewJobApplicationNotification;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell hiring staff about a new application (phase-04 §10.1, §10.2) — queued.
 *
 * Recipients: active users holding `job_applications.view_any` (HR, per §9.1), plus
 * `website.careers_notify_emails` (mail only). The CV is never attached. Nothing is sent while the
 * `job_applications` module is switched off, or for an application deleted before the worker ran.
 */
final class NotifyHrOfApplication implements ShouldQueue
{
    use NotifiesStaff;

    public bool $deleteWhenMissingModels = true;

    public function handle(JobApplicationReceived $event): void
    {
        if (! Modules::enabled('job_applications')) {
            return;
        }

        $application = JobApplication::query()->find($event->application->getKey());

        if (! $application instanceof JobApplication) {
            return;
        }

        $this->deliver(
            $this->usersHolding('job_applications.view_any'),
            $this->addressesFrom('website.careers_notify_emails'),
            new NewJobApplicationNotification($application),
        );
    }
}
