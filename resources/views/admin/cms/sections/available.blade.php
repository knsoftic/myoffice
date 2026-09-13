@extends('layouts.admin')

@section('title', 'Add a section')

{{--
    Add-section screen — admin.website.sections.available (phase-03 §7.1, §8.4). The no-JavaScript and
    deep-link form of the dialog on sections.index; a JSON request is answered by the controller instead.

    Controller variables (Admin\Cms\SectionController@available):
      $placement  App\Enums\Cms\SectionPlacement
      $groups     array<string, array{label: string, sort: int}>   SectionRegistry::groups()
      $types      array<string, array{key, label, description, icon, group, unique, required,
                                      disabled: bool, reason: ?string, existing_url: ?string}>
    Query string: page_id (the page placement).

    Writes: POST admin.website.sections.store {placement} — section_key, page_id, name.
--}}

@php
    use App\Enums\Cms\SectionPlacement;
    use App\Support\Cms\SectionRegistry;

    $groups = $groups ?? SectionRegistry::groups();
    $pageId = $placement === SectionPlacement::Page ? (request()->integer('page_id') ?: null) : null;
    $grouped = collect($types ?? [])->groupBy(fn (array $type): string => (string) $type['group'], true)
        ->sortBy(fn ($list, string $group): int => $groups[$group]['sort'] ?? 99);
    $backUrl = route('admin.website.sections.index', array_filter(['placement' => $placement->value, 'page_id' => $pageId]));
    $placementLabel = SectionRegistry::placements()[$placement->value]['label_plural'] ?? $placement->label();
@endphp

@section('header')
    <x-ui.page-header title="Add a section" :subtitle="$placementLabel" icon="plus" :back="$backUrl" />
@endsection

@section('content')
    <x-ui.card>
        @if ($grouped->isEmpty())
            <x-ui.empty-state icon="view-columns" title="No section types can be placed here" message="The section registry offers no type for this placement." />
        @else
            @include('admin.cms.sections.partials.add-form', [
                'placement' => $placement,
                'pageId' => $pageId,
                'addableByGroup' => $grouped,
                'groups' => $groups,
                'formId' => 'available-section-form',
            ])

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" :href="$backUrl">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="available-section-form" icon="plus">Add as a draft</x-ui.button>
                </div>
            </x-slot:footer>
        @endif
    </x-ui.card>
@endsection
