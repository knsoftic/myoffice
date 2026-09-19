@extends('layouts.admin')

{{--
    Edit a portfolio category with its SEO — admin.portfolio-categories.edit (phase-04 §8.1, §8.13).
    Variables and writes: see admin/marketing/partials/taxonomy-edit.blade.php.
--}}

@include('admin.marketing.partials.taxonomy-edit', [
    'taxonomy' => [
        'resource' => 'portfolio-categories',
        'module' => 'portfolio_categories',
        'title' => 'Portfolio categories',
        'icon' => 'rectangle-stack',
        'noun' => 'category',
        'image' => ['column' => 'image_media_id', 'upload' => 'image', 'label' => 'Category image', 'profile' => 'Card', 'relations' => ['imageAsset', 'image', 'imageMedia']],
        'description' => true,
        'iconField' => true,
    ],
])
