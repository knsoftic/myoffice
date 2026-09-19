@extends('layouts.admin')

{{--
    Edit a blog category with its SEO — admin.blog-categories.edit (phase-04 §8.1, §8.13).
    Variables and writes: see admin/marketing/partials/taxonomy-edit.blade.php.
    $publicUrl = route('site.blog.category', $term->slug) when the category is active.
--}}

@include('admin.marketing.partials.taxonomy-edit', [
    'taxonomy' => [
        'resource' => 'blog-categories',
        'module' => 'blog_categories',
        'title' => 'Blog categories',
        'icon' => 'folder',
        'noun' => 'category',
        'image' => ['column' => 'image_media_id', 'upload' => 'image', 'label' => 'Category image', 'profile' => 'Card', 'relations' => ['imageAsset', 'image', 'imageMedia']],
        'description' => true,
        'iconField' => true,
    ],
])
