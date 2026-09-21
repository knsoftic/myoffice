<?php

declare(strict_types=1);

namespace App\Support\Institute\Sitemap;

use App\Enums\CourseStatus;
use App\Models\Institute\CourseCategory;
use App\Support\Cms\Sitemap\EntitySitemapProvider;
use Illuminate\Database\Query\Builder;

/**
 * `/courses/category/{slug}` for every active category that actually has something published in it
 * (§89, phase-14-17 §7.10).
 *
 * An empty category is left out on purpose: the page renders, but it is a dead end for a visitor and a
 * thin page for a crawler, and listing it costs both of them a click.
 */
final class CourseCategorySitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'course_categories';
    }

    protected function module(): string
    {
        return 'courses';
    }

    protected function table(): string
    {
        return 'course_categories';
    }

    protected function morphClass(): ?string
    {
        return (new CourseCategory)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.courses.category';
    }

    protected function routeParameter(): string
    {
        return 'category';
    }

    protected function constrain(Builder $query): void
    {
        $query
            ->where('e.is_active', true)
            ->whereExists(static fn (Builder $inner) => $inner
                ->selectRaw('1')
                ->from('courses as c')
                ->whereColumn('c.course_category_id', 'e.id')
                ->where('c.status', CourseStatus::Published->value)
                ->whereNull('c.deleted_at'));
    }

    protected function priority(): string
    {
        return '0.5';
    }
}
