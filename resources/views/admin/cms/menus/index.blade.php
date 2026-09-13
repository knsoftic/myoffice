@extends('layouts.admin')

@section('title', 'Menus')

{{--
    Menus — admin.website.menus.index (phase-03 §7.2, §8.9; requirement §102).

    Controller variables (Admin\Cms\MenuController@index):
      $menus             LengthAwarePaginator<App\Models\Cms\Menu>   withCount: items_count, enabled_items_count,
                                                                     child_items_count
      $missingLocations  list<App\Enums\Cms\MenuLocation>            slots with no menu yet (G-1)
      $filters           array<string, mixed>
    Query string (CmsListRequest): search, page.

    One card per menu (one menu per slot, uq_menus_location) plus a "not created yet" card per empty slot.
    No store route exists in the contract (G-1): the website seeder creates the standard slots.
--}}

@php
    use App\Enums\Cms\MenuLocation;

    $missingLocations = $missingLocations ?? [];
    $filtered = ! empty($filters ?? []);
@endphp

@section('header')
    <x-ui.page-header title="Menus" subtitle="Header, footer and mobile navigation. Two levels deep at most: a link and its dropdown children." icon="bars-3" />
@endsection

@section('content')
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >
        <x-ui.filter-bar placeholder="Search menus…" :reset="route('admin.website.menus.index')" />

        <div x-show="navigating" x-cloak aria-hidden="true" class="card-grid-3">
            <x-ui.skeleton variant="card" :count="3" />
        </div>

        <div x-show="! navigating" class="space-y-4">
            @if ($menus->isEmpty() && ($filtered || $missingLocations === []))
                <x-ui.card>
                    <x-ui.empty-state icon="bars-3" :title="$filtered ? 'No menus match that search' : 'No menus yet'" :message="$filtered ? 'Clear the search to see every menu.' : 'The website seeder creates the header and footer menus.'">
                        @if ($filtered)
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.website.menus.index')">Clear search</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <div class="card-grid-3">
                    @foreach ($menus as $menu)
                        @php
                            $location = $menu->location instanceof MenuLocation ? $menu->location : MenuLocation::tryFrom((string) $menu->location);
                        @endphp
                        <x-ui.card :hover="true" class="flex h-full flex-col">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ $location?->label() ?? $menu->location }}</p>
                                    <h3 class="mt-0.5 truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $menu->name }}</h3>
                                    @if (filled($menu->description))
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $menu->description }}</p>
                                    @endif
                                </div>
                                <x-ui.badge :color="$menu->is_active ? 'emerald' : 'slate'" size="sm" :dot="true">{{ $menu->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                            </div>

                            <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
                                    <dd class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($menu->items_count ?? 0)) }}</dd>
                                    <dt class="text-2xs text-slate-500 dark:text-slate-400">items</dt>
                                </div>
                                <div class="rounded-lg bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
                                    <dd class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($menu->enabled_items_count ?? 0)) }}</dd>
                                    <dt class="text-2xs text-slate-500 dark:text-slate-400">enabled</dt>
                                </div>
                                <div class="rounded-lg bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
                                    <dd class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ (int) ($menu->child_items_count ?? 0) > 0 ? '2' : '1' }}</dd>
                                    <dt class="text-2xs text-slate-500 dark:text-slate-400">{{ (int) ($menu->child_items_count ?? 0) > 0 ? 'levels' : 'level' }}</dt>
                                </div>
                            </dl>

                            <div class="mt-auto flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                                @can('menus.view')
                                    <x-ui.button size="sm" icon="pencil" :href="route('admin.website.menus.show', $menu)">Build menu</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" icon="link" :href="route('admin.website.menus.link-check', $menu)">Link check</x-ui.button>
                                @endcan
                            </div>
                        </x-ui.card>
                    @endforeach

                    @unless ($filtered)
                        @foreach ($missingLocations as $location)
                            <x-ui.card class="flex h-full flex-col border-dashed">
                                <p class="text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ $location->label() }}</p>
                                <x-ui.empty-state
                                    :compact="true"
                                    icon="bars-3"
                                    title="Not created yet"
                                    :message="$location === MenuLocation::Mobile ? 'Optional: without a mobile menu the drawer shows the header menu.' : 'The website seeder creates this menu. Until it exists, this slot renders nothing.'"
                                />
                            </x-ui.card>
                        @endforeach
                    @endunless
                </div>

                @if ($menus->hasPages())
                    <x-ui.card :compact="true">
                        <x-ui.pagination-summary :paginator="$menus" label="menus" />
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>
@endsection
