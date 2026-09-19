<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Models\Cms\SuccessStory;
use App\Services\Cms\TestimonialFeed;
use App\Support\Cms\VideoUrl;
use App\Support\RichText;

/**
 * `success_stories` section (rendered with a modal — there is no detail route, §2.12): published stories,
 * featured first. The story is sanitised **again** here (D25 — the database is not a trust boundary).
 */
final class SuccessStoriesSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'success_stories';
    }

    protected function module(): string
    {
        return 'success_stories';
    }

    protected function build(array $options): array
    {
        $items = app(TestimonialFeed::class)
            ->successStories($options['limit'], $options['course_id'], $options['featured_only'])
            ->map(fn (SuccessStory $story): array => [
                'id' => (int) $story->getKey(),
                'student_name' => (string) $story->student_name,
                'course_name' => $story->course_name,
                'headline' => $story->headline,
                'story' => RichText::sanitize((string) $story->story),
                'achievement' => $story->achievement,
                'company_name' => $story->company_name,
                'platform' => $story->platform,
                'video_embed_url' => VideoUrl::embedUrl($story->video_url),
                'photo' => $this->image($story->photo, ImageProfile::Thumbnail),
                'is_featured' => (bool) $story->is_featured,
            ])
            ->values()
            ->all();

        return ['items' => $items];
    }
}
