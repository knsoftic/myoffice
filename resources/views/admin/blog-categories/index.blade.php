@extends('layouts.admin')

{{--
    Blog categories — admin.blog-categories.index (phase-04 §8.1, §2.13, §7.2).
    Variables and writes: see admin/marketing/partials/taxonomy-manager.blade.php.
    Children: blog_posts_count (withCount('posts')); the image relation eager-loaded.
--}}

@include('admin.marketing.partials.taxonomy-manager', [
    'taxonomy' => [
        'resource' => 'blog-categories',
        'module' => 'blog_categories',
        'title' => 'Blog categories',
        'subtitle' => 'Each active category has its own page at /blog/category/{slug}.',
        'icon' => 'folder',
        'noun' => 'category',
        'nounPlural' => 'blog categories',
        'addLabel' => 'Add category',
        'emptyTitle' => 'No blog categories yet',
        'emptyMessage' => 'Categories give every post a home and a page of its own.',
        'children' => [
            ['count' => 'blog_posts_count', 'singular' => 'post', 'plural' => 'posts', 'route' => 'admin.blog-posts.index', 'filter' => 'category'],
        ],
        'image' => ['column' => 'image_media_id', 'upload' => 'image', 'label' => 'Category image', 'profile' => 'Card', 'relations' => ['imageAsset', 'image', 'imageMedia']],
        'description' => true,
        'iconField' => true,
        'seo' => true,
        'reassign' => true,
        'publicRoute' => 'site.blog.category',
    ],
])
