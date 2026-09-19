<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Enums\TestimonialType;
use App\Models\Cms\Testimonial;
use App\Services\Cms\TestimonialFeed;

/**
 * `testimonials` section: approved testimonials only (through `TestimonialFeed`), featured first. The
 * review is plain text; the partial escapes it.
 */
final class TestimonialsSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'testimonials';
    }

    protected function module(): string
    {
        return 'testimonials';
    }

    protected function build(array $options): array
    {
        $type = $options['type'] === null ? null : TestimonialType::tryFrom($options['type']);

        $items = app(TestimonialFeed::class)
            ->testimonials($options['limit'], $type, $options['featured_only'])
            ->map(fn (Testimonial $testimonial): array => [
                'id' => (int) $testimonial->getKey(),
                'type' => $testimonial->type instanceof TestimonialType ? $testimonial->type->value : (string) $testimonial->type,
                'author_name' => (string) $testimonial->author_name,
                'author_designation' => $testimonial->author_designation,
                'author_company' => $testimonial->author_company,
                'course_name' => $testimonial->course_name,
                'rating' => $testimonial->rating,
                'review' => (string) $testimonial->review,
                'review_date' => $this->date($testimonial->review_date),
                'photo' => $this->image($testimonial->authorPhoto, ImageProfile::Thumbnail),
                'is_featured' => (bool) $testimonial->is_featured,
            ])
            ->values()
            ->all();

        return ['items' => $items];
    }
}
