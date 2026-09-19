<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Models\Cms\StudentReview;
use App\Models\Cms\Testimonial;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Modules;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * What is waiting in the two moderation queues (phase-04 §8.12): pending testimonials and pending student
 * reviews, each linking to its Pending tab. A standing count. The student-review line appears only while
 * that module is on and the viewer may open its queue.
 */
final class PendingModerationWidget extends Widget
{
    public function key(): string
    {
        return 'pending_moderation';
    }

    public function title(): string
    {
        return 'Waiting for approval';
    }

    public function icon(): string
    {
        return 'chat-bubble-left-right';
    }

    public function permission(): ?string
    {
        return 'testimonials.view_any';
    }

    public function module(): ?string
    {
        return 'testimonials';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 45;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.testimonials.index', ['tab' => 'pending']);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing waiting for approval.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $queues = [[
                'key' => 'testimonials',
                'label' => 'Testimonials',
                'count' => Testimonial::query()->pending()->count(),
                'href' => $this->routeUrlWithQuery('admin.testimonials.index', ['tab' => 'pending']),
            ]];

            $user = Auth::user();

            if (Modules::enabled('student_reviews') && (! $user instanceof User || $user->can('student_reviews.view_any'))) {
                $queues[] = [
                    'key' => 'student_reviews',
                    'label' => 'Student reviews',
                    'count' => StudentReview::query()->pending()->count(),
                    'href' => $this->routeUrlWithQuery('admin.student-reviews.index', ['tab' => 'pending']),
                ];
            }
        } catch (Throwable) {
            return ['available' => false, 'total' => 0, 'queues' => []];
        }

        return [
            'available' => true,
            'total' => array_sum(array_column($queues, 'count')),
            'queues' => $queues,
        ];
    }
}
