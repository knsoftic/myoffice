<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\Cms\ContentStatus;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Blog output (phase-04 §8.12): posts published in the range, posts scheduled ahead, and drafts — all
 * through `BlogPost::visibleTo()`, so an author counts only its own drafts (§9.1.1).
 */
final class BlogActivityWidget extends Widget
{
    public function key(): string
    {
        return 'blog_activity';
    }

    public function title(): string
    {
        return 'Blog activity';
    }

    public function icon(): string
    {
        return 'newspaper';
    }

    public function permission(): ?string
    {
        return 'blog_posts.view_any';
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
        return 60;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.blog-posts.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $query = BlogPost::query();
            $user = Auth::user();

            if ($user instanceof User) {
                $query->visibleTo($user);
            }

            $published = (clone $query)
                ->where('blog_posts.status', ContentStatus::Published->value)
                ->whereBetween('blog_posts.published_at', [$range->storageStart()->format('Y-m-d H:i:s'), $range->storageEnd()->format('Y-m-d H:i:s')])
                ->count();

            $scheduled = (clone $query)
                ->where('blog_posts.status', ContentStatus::Scheduled->value)
                ->where('blog_posts.published_at', '>', Carbon::now())
                ->count();

            $drafts = (clone $query)->where('blog_posts.status', ContentStatus::Draft->value)->count();
        } catch (Throwable) {
            return ['available' => false, 'published' => 0, 'scheduled' => 0, 'drafts' => 0, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'published' => $published,
            'scheduled' => $scheduled,
            'drafts' => $drafts,
            'range_label' => $range->label(),
            'links' => [
                'published' => $this->routeUrlWithQuery('admin.blog-posts.index', ['tab' => 'published']),
                'scheduled' => $this->routeUrlWithQuery('admin.blog-posts.index', ['tab' => 'scheduled']),
                'drafts' => $this->routeUrlWithQuery('admin.blog-posts.index', ['tab' => 'draft']),
            ],
        ];
    }
}
