@extends('layouts.admin')

{{--
    Technologies — admin.technologies.index (phase-04 §8.1, §2.4, §7.2).
    Variables and writes: see admin/marketing/partials/taxonomy-manager.blade.php.
    Children: services_count and portfolio_items_count; the logo relation eager-loaded.
    Deleting a technology only detaches it (§6.2), so there is no reassign step. Edited in a dialog.
--}}

@include('admin.marketing.partials.taxonomy-manager', [
    'taxonomy' => [
        'resource' => 'technologies',
        'module' => 'technologies',
        'title' => 'Technologies',
        'subtitle' => 'The stack chips on services and case studies, and the public technology filter.',
        'icon' => 'server-stack',
        'noun' => 'technology',
        'nounPlural' => 'technologies',
        'addLabel' => 'Add technology',
        'nameMax' => 100,
        'emptyTitle' => 'No technologies yet',
        'emptyMessage' => 'Add the languages, frameworks and platforms you build with.',
        'children' => [
            ['count' => 'services_count', 'singular' => 'service', 'plural' => 'services', 'route' => 'admin.services.index', 'filter' => 'technology'],
            ['count' => 'portfolio_items_count', 'singular' => 'project', 'plural' => 'projects', 'route' => 'admin.portfolio.index', 'filter' => 'technology'],
        ],
        'image' => ['column' => 'logo_media_id', 'upload' => 'logo', 'label' => 'Logo', 'profile' => 'Logo', 'relations' => ['logoAsset', 'logo', 'logoMedia']],
        'color' => true,
        'reassign' => false,
    ],
])
