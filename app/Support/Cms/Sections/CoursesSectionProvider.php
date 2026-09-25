<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;

/**
 * `courses` section: the published catalogue as teaser cards.
 *
 * **A section view for this already existed and nothing could reach it.** `site/sections/courses`
 * has been in the repository since the institute phases — a one-line include of Phase 3's shared
 * teaser, exactly like `site/sections/services` beside it — but `MarketingSectionTypes` declares
 * Phase 4's nine types and `courses` is not one of them. No type meant no provider, no provider
 * meant `SectionService::place('courses', …)` threw `UnknownSectionTypeException`, and the view was
 * unreachable from the editor. This is the missing half.
 *
 * **Published, and in a category that is switched on.** The same predicate the public catalogue
 * uses, so a card on the home page can never link to a course whose own page 404s — a section that
 * advertises something the site then denies is worse than an empty section.
 *
 * **`media` is null, deliberately.** Courses carry `image_path` as a plain column rather than a
 * `media_assets` row (D24's library does not cover them), and handing the teaser a raw path would
 * route around the image pipeline that every other card goes through. The teaser hides its image
 * block when there is none, so a course renders as a clean text card — which is honest about the
 * fact that nobody has attached an image yet.
 */
final class CoursesSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'courses';
    }

    protected function module(): string
    {
        return 'courses';
    }

    protected function build(array $options): array
    {
        $query = Course::query()
            ->published()
            // A course in a switched-off category is not on the site, so it is not in this section
            // either. Mirrors `PublicCourseService::catalogue()`.
            ->whereHas('category', static fn ($category) => $category->where('is_active', true))
            ->with('category')
            ->when($options['featured_only'], static fn ($builder) => $builder->where('courses.is_featured', true))
            ->catalogueOrder();

        if ($options['category'] !== null) {
            $query->whereHas(
                'category',
                static fn ($category) => $category->where('is_active', true)->where('slug', $options['category']),
            );
        }

        $items = $query->limit($options['limit'])->get()->map(function (Course $course): array {
            $category = $course->category instanceof CourseCategory && (bool) $course->category->is_active
                ? $course->category
                : null;

            /*
            | The meta line is what a prospective student is actually comparing, and it is the one
            | thing a course card has that a service card does not: how long it takes. The fee is
            | rendered separately by the card so it can be styled as a price rather than as prose.
            */
            $duration = $course->duration_value === null
                ? null
                : trim(sprintf('%d %s', $course->duration_value, (string) $course->duration_unit?->value));

            return [
                // Phase 3's shared teaser card reads title / excerpt / media / meta.
                'title' => (string) $course->name,
                'excerpt' => $course->short_description,
                'media' => null,
                'meta' => $category === null ? $duration : trim((string) $category->name.($duration === null ? '' : ' · '.$duration)),
                'id' => (int) $course->getKey(),
                'name' => (string) $course->name,
                'slug' => (string) $course->slug,
                'code' => (string) $course->code,
                'url' => $this->url('site.courses.show', ['course' => $course->slug]),
                'short_description' => $course->short_description,
                'duration' => $duration,
                // A string, never cast: `course_fee` is decimal(15,2) and money never becomes a
                // float on the way to a view (golden rule 4).
                'course_fee' => (string) $course->course_fee,
                'is_featured' => (bool) $course->is_featured,
                'category' => $category === null ? null : ['name' => (string) $category->name, 'slug' => (string) $category->slug],
            ];
        })->values()->all();

        return [
            'items' => $items,
            'index_url' => $this->url('site.courses.index'),
        ];
    }
}
