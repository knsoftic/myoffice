@extends('layouts.admin')

{{--
    Blog tags — admin.blog-tags.index (phase-04 §8.1, §2.14, §7.2).
    Variables and writes: see admin/marketing/partials/taxonomy-manager.blade.php.
    Children: blog_posts_count (withCount('posts')). No image and no SEO, so a tag is edited in a dialog.
    Tags are also created on the fly from the post editor (BlogService::syncTags matches them by slug).
--}}

@include('admin.marketing.partials.taxonomy-manager', [
    'taxonomy' => [
        'resource' => 'blog-tags',
        'module' => 'blog_tags',
        'title' => 'Blog tags',
        'subtitle' => 'An inactive tag keeps its posts but leaves the public tag cloud.',
        'icon' => 'tag',
        'noun' => 'tag',
        'nounPlural' => 'tags',
        'addLabel' => 'Add tag',
        'nameMax' => 100,
        'emptyTitle' => 'No tags yet',
        'emptyMessage' => 'Tags are created here or typed straight into a post.',
        'children' => [
            ['count' => 'blog_posts_count', 'singular' => 'post', 'plural' => 'posts', 'route' => 'admin.blog-posts.index', 'filter' => 'tag'],
        ],
        'activeHelp' => 'Inactive tags keep their posts but leave the public tag cloud and tag pages.',
        'reassign' => true,
        'publicRoute' => 'site.blog.tag',
    ],
])
