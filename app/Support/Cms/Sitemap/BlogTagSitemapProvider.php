<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\ContentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * `/blog/tag/{slug}` for every active tag that has at least one public post — an empty tag page is thin
 * content a search engine should not be sent to. Tags carry no `seo_meta` (phase-04 §2.14).
 */
final class BlogTagSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'blog_tags';
    }

    protected function module(): string
    {
        return 'blog_posts';
    }

    protected function table(): string
    {
        return 'blog_tags';
    }

    protected function morphClass(): ?string
    {
        return null;
    }

    protected function routeName(): string
    {
        return 'site.blog.tag';
    }

    protected function routeParameter(): string
    {
        return 'blogTag';
    }

    protected function constrain(Builder $query): void
    {
        $query->where('e.is_active', true)
            ->whereExists(static function ($exists): void {
                $exists->selectRaw('1')
                    ->from('blog_post_blog_tag as pivot')
                    ->join('blog_posts as p', 'p.id', '=', 'pivot.blog_post_id')
                    ->whereColumn('pivot.blog_tag_id', 'e.id')
                    ->where('p.status', ContentStatus::Published->value)
                    ->whereNotNull('p.published_at')
                    ->where('p.published_at', '<=', Carbon::now())
                    ->whereNull('p.deleted_at');
            });
    }

    protected function priority(): string
    {
        return '0.4';
    }
}
