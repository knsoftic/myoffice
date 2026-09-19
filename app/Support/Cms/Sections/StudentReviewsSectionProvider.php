<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Models\Cms\StudentReview;
use App\Services\Cms\TestimonialFeed;
use App\Support\Cms\VideoUrl;

/**
 * `student_reviews` section: approved student reviews only, optionally for one course (`course_id`). A
 * video is exposed only as an embed URL built from the parsed YouTube / Vimeo id — never the stored string.
 */
final class StudentReviewsSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'student_reviews';
    }

    protected function module(): string
    {
        return 'student_reviews';
    }

    protected function build(array $options): array
    {
        $items = app(TestimonialFeed::class)
            ->studentReviews($options['limit'], $options['course_id'], $options['featured_only'])
            ->map(fn (StudentReview $review): array => [
                'id' => (int) $review->getKey(),
                'student_name' => (string) $review->student_name,
                'course_name' => $review->course_name,
                'rating' => $review->rating,
                'review' => (string) $review->review,
                'video_embed_url' => VideoUrl::embedUrl($review->video_url),
                'photo' => $this->image($review->studentPhoto, ImageProfile::Thumbnail),
                'is_featured' => (bool) $review->is_featured,
            ])
            ->values()
            ->all();

        return ['items' => $items];
    }
}
