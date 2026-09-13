@extends('layouts.admin')

@section('title', 'Link check')

{{--
    Link check — admin.website.menus.link-check (phase-03 §7.2, §8.9, §10.4 cms:check-links; R-3).
    Every item of one menu whose target no longer resolves, at once. Such items are already hidden from
    visitors; this screen is how an editor notices.

    Controller variables (Admin\Cms\MenuController@linkCheck):
      $menu   App\Models\Cms\Menu
      $items  Collection<array{id: int, label: string, link_type: string, is_enabled: bool, reason: string}>
    A JSON request is answered {"menu": id, "items": [...]} by the controller; this is the HTML answer.
--}}

@php
    use App\Enums\Cms\MenuItemLinkType;

    $items = collect($items ?? []);
@endphp

@section('header')
    <x-ui.page-header
        :title="'Link check — '.$menu->name"
        subtitle="Items whose page, route, address or section no longer resolves. Visitors never see them; fix or remove them in the builder."
        icon="link"
        :back="route('admin.website.menus.show', $menu)"
        :badge="$items->isEmpty() ? 'All links resolve' : $items->count().' broken'"
        :badge-color="$items->isEmpty() ? 'emerald' : 'rose'"
    />
@endsection

@section('content')
    <x-ui.table :is-empty="$items->isEmpty()" :columns="4">
        <x-slot:head>
            <th scope="col" class="px-4 py-3">Item</th>
            <th scope="col" class="px-4 py-3">Links to</th>
            <th scope="col" class="px-4 py-3">Problem</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @foreach ($items as $row)
            @php
                $linkType = MenuItemLinkType::tryFrom((string) ($row['link_type'] ?? ''));
            @endphp
            <tr>
                <td>
                    <p class="font-medium text-slate-900 dark:text-white">{{ $row['label'] }}</p>
                    @unless ($row['is_enabled'] ?? true)
                        <x-ui.badge color="slate" variant="outline" size="sm" class="mt-1">Disabled</x-ui.badge>
                    @endunless
                </td>
                <td>
                    @if ($linkType)
                        <x-ui.badge :color="$linkType->color()" size="sm">{{ $linkType->label() }}</x-ui.badge>
                    @endif
                </td>
                <td><x-ui.badge color="rose" size="sm" icon="exclamation-triangle">{{ \Illuminate\Support\Str::ucfirst((string) $row['reason']) }}</x-ui.badge></td>
                <td class="text-right">
                    <x-ui.button size="sm" variant="secondary" icon="pencil" :href="route('admin.website.menus.show', $menu)">Fix in the builder</x-ui.button>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state icon="check-circle" title="Every link resolves" message="No item in this menu points at a draft, trashed or missing page, at a route that no longer exists, or at a section anchor nothing uses." />
        </x-slot:empty>
    </x-ui.table>
@endsection
