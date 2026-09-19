<?php

declare(strict_types=1);

namespace App\Notifications\Cms;

use App\Contracts\Cms\Moderatable;
use App\Models\Cms\StudentReview;
use App\Notifications\Cms\Concerns\BuildsCmsNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * "A review is waiting for approval" (phase-04 §10.2) — database only, to users holding
 * `testimonials.approve` (testimonials) or `student_reviews.approve` (student reviews), with a link to
 * the Pending tab of the matching queue.
 */
final class PendingModerationNotification extends Notification
{
    use BuildsCmsNotification;

    public function __construct(
        public readonly Moderatable&Model $record,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, ['database']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $isStudentReview = $this->record instanceof StudentReview;

        return [
            'kind' => $isStudentReview ? 'student_review.pending' : 'testimonial.pending',
            'module' => $isStudentReview ? 'student_reviews' : 'testimonials',
            'record_type' => $this->record::class,
            'record_id' => (int) $this->record->getKey(),
            'title' => $this->record->moderationLabel().' is waiting for approval',
            'url' => $isStudentReview
                ? $this->link('admin.student-reviews.index', ['status' => 'pending'], '/admin/student-reviews?status=pending')
                : $this->link('admin.testimonials.index', ['status' => 'pending'], '/admin/testimonials?status=pending'),
        ];
    }
}
