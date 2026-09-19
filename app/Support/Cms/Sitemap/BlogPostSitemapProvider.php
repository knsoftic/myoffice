<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\BlogPost;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * `/blog/{slug}` for every post that is published **and** whose `published_at` has passed — a scheduled
 * post with a past moment is not public until the scheduler flips it (phase-04 §9.2), so it is not here
 * either. `lastmod` is the later of `published_at` and `updated_at`.
 */
final class BlogPostSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'blog';
    }

    protected function module(): string
    {
        return 'blog_posts';
    }

    protected function table(): string
    {
        return 'blog_posts';
    }

    protected function morphClass(): ?string
    {
        return (new BlogPost)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.blog.show';
    }

    protected function routeParameter(): string
    {
        return 'blogPost';
    }

    protected function lastmodColumn(): ?string
    {
        return 'published_at';
    }

    protected function constrain(Builder $query): void
    {
        $query->where('e.status', ContentStatus::Published->value)
            ->whereNotNull('e.published_at')
            ->where('e.published_at', '<=', Carbon::now());
    }

    protected function priority(): string
    {
        return '0.7';
    }
}
