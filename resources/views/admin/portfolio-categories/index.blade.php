@extends('layouts.admin')

{{--
    Portfolio categories — admin.portfolio-categories.index (phase-04 §8.1, §2.6, §7.2).
    Variables and writes: see admin/marketing/partials/taxonomy-manager.blade.php.
    Children: portfolio_items_count (withCount('portfolioItems')); the image relation eager-loaded.
--}}

@include('admin.marketing.partials.taxonomy-manager', [
    'taxonomy' => [
        'resource' => 'portfolio-categories',
        'module' => 'portfolio_categories',
        'title' => 'Portfolio categories',
        'subtitle' => 'The groups case studies are filtered by on the website.',
        'icon' => 'rectangle-stack',
        'noun' => 'category',
        'nounPlural' => 'portfolio categories',
        'addLabel' => 'Add category',
        'emptyTitle' => 'No portfolio categories yet',
        'emptyMessage' => 'Group your projects so visitors can browse the kind of work they need.',
        'children' => [
            ['count' => 'portfolio_items_count', 'singular' => 'project', 'plural' => 'projects', 'route' => 'admin.portfolio.index', 'filter' => 'category'],
        ],
        'image' => ['column' => 'image_media_id', 'upload' => 'image', 'label' => 'Category image', 'profile' => 'Card', 'relations' => ['imageAsset', 'image', 'imageMedia']],
        'description' => true,
        'iconField' => true,
        'seo' => true,
        'reassign' => true,
    ],
])
