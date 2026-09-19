<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Enums\ApprovalStatus;
use App\Events\Cms\TestimonialSubmitted;
use App\Listeners\Cms\Concerns\NotifiesStaff;
use App\Models\Cms\StudentReview;
use App\Notifications\Cms\PendingModerationNotification;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the moderators something is waiting (phase-04 §10.1, §10.2) — queued, database channel only.
 *
 * A testimonial goes to users holding `testimonials.approve`; a student review to `student_reviews.approve`.
 * Nothing is sent when the record was already moderated (or deleted) by the time the worker runs, or while
 * its module is switched off.
 */
final class NotifyStaffOfPendingModeration implements ShouldQueue
{
    use NotifiesStaff;

    public bool $deleteWhenMissingModels = true;

    public function handle(TestimonialSubmitted $event): void
    {
        $record = $event->record;
        $module = $record instanceof StudentReview ? 'student_reviews' : 'testimonials';

        if (! Modules::enabled($module)) {
            return;
        }

        $fresh = $record->newQuery()->find($record->getKey());

        if ($fresh === null || $fresh->moderationStatus() !== ApprovalStatus::Pending) {
            return;
        }

        $this->deliver($this->usersHolding($module.'.approve'), [], new PendingModerationNotification($fresh));
    }
}
