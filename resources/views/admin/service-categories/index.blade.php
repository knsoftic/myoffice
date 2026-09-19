@extends('layouts.admin')

{{--
    Service categories — admin.service-categories.index (phase-04 §8.1, §2.2, §7.2).
    Variables and writes: see admin/marketing/partials/taxonomy-manager.blade.php.
    Children: services_count (withCount('services')); the image relation eager-loaded.
--}}

@include('admin.marketing.partials.taxonomy-manager', [
    'taxonomy' => [
        'resource' => 'service-categories',
        'module' => 'service_categories',
        'title' => 'Service categories',
        'subtitle' => 'The groups the public service catalogue is filtered by.',
        'icon' => 'squares-2x2',
        'noun' => 'category',
        'nounPlural' => 'service categories',
        'addLabel' => 'Add category',
        'emptyTitle' => 'No service categories yet',
        'emptyMessage' => 'Group your services so visitors can filter the catalogue.',
        'children' => [
            ['count' => 'services_count', 'singular' => 'service', 'plural' => 'services', 'route' => 'admin.services.index', 'filter' => 'category'],
        ],
        'image' => ['column' => 'image_media_id', 'upload' => 'image', 'label' => 'Category image', 'profile' => 'Card', 'relations' => ['imageAsset', 'image', 'imageMedia']],
        'description' => true,
        'iconField' => true,
        'seo' => true,
        'reassign' => true,
    ],
])
