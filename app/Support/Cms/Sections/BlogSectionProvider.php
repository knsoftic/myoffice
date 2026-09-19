<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\User;

/**
 * `blog` teaser section: the latest public posts (published and past their `published_at`), optionally
 * featured only or from one active category.
 */
final class BlogSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'blog';
    }

    protected function module(): string
    {
        return 'blog_posts';
    }

    protected function defaultLimit(): int
    {
        return 3;
    }

    protected function build(array $options): array
    {
        $query = BlogPost::query()->public()
            ->with(['featuredImage', 'category', 'author'])
            ->when($options['featured_only'], static fn ($builder) => $builder->where('blog_posts.is_featured', true))
            ->latestPublished();

        if ($options['category'] !== null) {
            $query->whereHas('category', static fn ($category) => $category->public()->where('slug', $options['category']));
        }

        $items = $query->limit($options['limit'])->get()->map(fn (BlogPost $post): array => [
            'id' => (int) $post->getKey(),
            'title' => (string) $post->title,
            'slug' => (string) $post->slug,
            'url' => $this->url('site.blog.show', ['blogPost' => $post->slug]),
            'excerpt' => $post->excerpt,
            'published_at' => $this->moment($post->published_at),
            'reading_minutes' => $post->reading_minutes,
            'image' => $this->image($post->featuredImage, ImageProfile::Card),
            'image_alt' => $post->featured_image_alt,
            'author' => $post->author instanceof User ? (string) $post->author->name : null,
            'category' => $post->category instanceof BlogCategory && $post->category->isActive()
                ? [
                    'name' => (string) $post->category->name,
                    'slug' => (string) $post->category->slug,
                    'url' => $this->url('site.blog.category', ['blogCategory' => $post->category->slug]),
                ]
                : null,
        ])->values()->all();

        return [
            'items' => $items,
            'index_url' => $this->url('site.blog.index'),
        ];
    }
}
