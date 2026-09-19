<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The five most-read posts in the range (phase-04 §8.12) — counted from `blog_post_views` (de-duplicated
 * reads per visitor per day), **not** from the lifetime `views_count` cache, and limited to the posts the
 * viewer may see (§9.1.1).
 */
final class TopViewedPostsWidget extends Widget
{
    private const LIMIT = 5;

    public function key(): string
    {
        return 'top_viewed_posts';
    }

    public function title(): string
    {
        return 'Most-read posts';
    }

    public function icon(): string
    {
        return 'chart-bar';
    }

    public function permission(): ?string
    {
        return 'blog_posts.view_reports';
    }

    public function module(): ?string
    {
        return 'blog_posts';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 61;
    }

    public function padded(): bool
    {
        return false;
    }

    public function emptyMessage(): ?string
    {
        return 'No post was read in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $views = $range->applyDates(DB::table('blog_post_views'), 'viewed_on')
                ->selectRaw('blog_post_id, count(*) as views')
                ->groupBy('blog_post_id');

            $query = BlogPost::query()
                ->joinSub($views, 'v', 'v.blog_post_id', '=', 'blog_posts.id')
                ->orderByDesc('v.views')
                ->orderByDesc('blog_posts.id')
                ->limit(self::LIMIT);

            $user = Auth::user();

            if ($user instanceof User) {
                $query->visibleTo($user);
            }

            $posts = $query->get(['blog_posts.id', 'blog_posts.title', 'blog_posts.slug', 'blog_posts.status', 'v.views']);
        } catch (Throwable) {
            return ['available' => false, 'posts' => [], 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'range_label' => $range->label(),
            'posts' => $posts->map(fn (BlogPost $post): array => [
                'id' => (int) $post->getKey(),
                'title' => (string) $post->title,
                'views' => (int) $post->getAttribute('views'),
                'status' => $post->status?->value,
                'stats_url' => $this->routeUrl('admin.blog-posts.stats', ['post' => $post->getKey()]),
            ])->values()->all(),
        ];
    }
}
