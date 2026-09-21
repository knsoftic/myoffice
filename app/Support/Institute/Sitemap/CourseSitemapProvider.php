<?php

declare(strict_types=1);

namespace App\Support\Institute\Sitemap;

use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Support\Cms\Sitemap\EntitySitemapProvider;
use Illuminate\Database\Query\Builder;

/**
 * `/courses/{slug}` for every published, indexable course (§89–90, phase-14-17 §7.10).
 *
 * **`is_indexable = false` is honoured here, not only in a meta tag.** Telling a crawler not to index a
 * page and then listing it in the sitemap is two instructions that contradict each other, and the
 * crawler picks whichever it likes.
 *
 * A course in a switched-off category is excluded for the same reason the catalogue excludes it: the
 * page 404s, and a sitemap full of 404s is how a site loses its crawl budget.
 */
final class CourseSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'courses';
    }

    protected function module(): string
    {
        return 'courses';
    }

    protected function table(): string
    {
        return 'courses';
    }

    protected function morphClass(): ?string
    {
        return (new Course)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.courses.show';
    }

    protected function routeParameter(): string
    {
        return 'course';
    }

    protected function constrain(Builder $query): void
    {
        $query
            ->where('e.status', CourseStatus::Published->value)
            ->where('e.is_indexable', true)
            ->whereExists(static fn (Builder $inner) => $inner
                ->selectRaw('1')
                ->from('course_categories as cc')
                ->whereColumn('cc.id', 'e.course_category_id')
                ->where('cc.is_active', true)
                ->whereNull('cc.deleted_at'));
    }

    protected function priority(): string
    {
        return '0.7';
    }
}
