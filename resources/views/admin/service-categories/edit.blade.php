@extends('layouts.admin')

{{--
    Edit a service category with its SEO — admin.service-categories.edit (phase-04 §8.1, §8.13).
    Variables and writes: see admin/marketing/partials/taxonomy-edit.blade.php.
--}}

@include('admin.marketing.partials.taxonomy-edit', [
    'taxonomy' => [
        'resource' => 'service-categories',
        'module' => 'service_categories',
        'title' => 'Service categories',
        'icon' => 'squares-2x2',
        'noun' => 'category',
        'image' => ['column' => 'image_media_id', 'upload' => 'image', 'label' => 'Category image', 'profile' => 'Card', 'relations' => ['imageAsset', 'image', 'imageMedia']],
        'description' => true,
        'iconField' => true,
    ],
])
