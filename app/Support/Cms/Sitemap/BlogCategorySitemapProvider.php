<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Models\Cms\BlogCategory;
use Illuminate\Database\Query\Builder;

/**
 * `/blog/category/{slug}` for every active blog category (an inactive one 404s, phase-04 §8.11).
 */
final class BlogCategorySitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'blog_categories';
    }

    protected function module(): string
    {
        return 'blog_posts';
    }

    protected function table(): string
    {
        return 'blog_categories';
    }

    protected function morphClass(): ?string
    {
        return (new BlogCategory)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.blog.category';
    }

    protected function routeParameter(): string
    {
        return 'blogCategory';
    }

    protected function constrain(Builder $query): void
    {
        $query->where('e.is_active', true);
    }

    protected function priority(): string
    {
        return '0.5';
    }
}
