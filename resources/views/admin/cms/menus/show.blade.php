@extends('layouts.admin')

@section('title', 'Menu builder')

{{--
    Menu builder — admin.website.menus.show (phase-03 §7.2, §8.9; requirement §102; INV-6).

    Controller variables (Admin\Cms\MenuController@show):
      $menu     App\Models\Cms\Menu
      $tree     Collection<MenuItem>   top-level items, sort_order asc, with `children` and `page` loaded; disabled included
      $urls     array<int, ?string>    item id => MenuService::resolveUrl() (computed, never stored)
      $broken   array<int, string>     item id => why its target does not resolve (absent = fine)
      $options  array{link_types: array, visibility: array, pages: Collection<Page>, anchors: list<string>,
                     routes: array<string, string> (route name => uri), icons: list<string>,
                     parents: list<array{id: int, label: string}>}
      $can      array{create: bool, edit: bool, toggle: bool, delete: bool}
    The tree is always whole: reordering posts the whole tree (INV-5), so there is no filter here.

    Writes:
      PUT    admin.website.menus.update       {menu}  name, description, is_active
      POST   admin.website.menus.items.store  {menu}  label, link_type, page_id, route_name, url, anchor,
                                                      icon, open_new_tab, rel_nofollow, visibility,
                                                      is_enabled, parent_id, _item_id = 'new'
      PUT    admin.website.menu-items.update  {item}  same fields, _item_id = {id}
      POST   admin.website.menu-items.toggle  {item}  enabled
      DELETE admin.website.menu-items.destroy {item}
      POST   admin.website.menus.reorder      {menu}  tree = [{id, children: [{id}]}] (JSON)
--}}

@php
    use App\Enums\Cms\MenuItemLinkType;
    use App\Enums\Cms\MenuLocation;
    use App\Enums\Cms\MenuVisibility;

    $can = array_merge(['create' => false, 'edit' => false, 'toggle' => false, 'delete' => false], $can ?? []);
    $canEdit = (bool) $can['edit'];
    $canCreate = (bool) $can['create'];
    $canToggle = (bool) $can['toggle'];
    $canDelete = (bool) $can['delete'];
    $options = $options ?? [];
    $tree = collect($tree ?? []);
    $resolvedUrls = $urls ?? [];
    $brokenReasons = $broken ?? [];
    $pageOptions = collect($options['pages'] ?? []);
    $routeOptions = (array) ($options['routes'] ?? []);
    $anchorOptions = collect($options['anchors'] ?? [])->mapWithKeys(fn ($anchor): array => [(string) $anchor => (string) $anchor])->all();
    $location = $menu->location instanceof MenuLocation ? $menu->location : MenuLocation::tryFrom((string) $menu->location);

    $itemCount = $tree->count() + $tree->sum(fn ($item) => $item->relationLoaded('children') ? $item->children->count() : 0);
    $brokenCount = count($brokenReasons);

    $parentOptions = collect($options['parents'] ?? $tree->map(fn ($item): array => ['id' => $item->id, 'label' => $item->label]))
        ->mapWithKeys(fn (array $parent): array => [(string) $parent['id'] => (string) $parent['label']])->all();

    $iconNames = ! empty($options['icons']) ? array_values((array) $options['icons']) : [
        'home', 'information-circle', 'academic-cap', 'briefcase', 'wrench-screwdriver', 'newspaper', 'envelope', 'phone',
        'whatsapp', 'user-circle', 'arrow-right-on-rectangle', 'question-mark-circle', 'document-text', 'globe-alt', 'star',
    ];
    sort($iconNames);

    // Re-open the dialog with the refused input after a failed save.
    $oldItemId = old('_item_id');
    $oldItem = $oldItemId === null ? null : [
        'id' => $oldItemId === 'new' ? null : (int) $oldItemId,
        'label' => old('label'),
        'link_type' => old('link_type', MenuItemLinkType::Page->value),
        'page_id' => old('page_id'),
        'route_name' => old('route_name'),
        'url' => old('url'),
        'anchor' => old('anchor'),
        'icon' => old('icon'),
        'open_new_tab' => (bool) old('open_new_tab'),
        'rel_nofollow' => (bool) old('rel_nofollow'),
        'visibility' => old('visibility', MenuVisibility::All->value),
        'is_enabled' => (bool) old('is_enabled', true),
        'parent_id' => old('parent_id'),
        'has_children' => false,
        'action' => $oldItemId === 'new' ? route('admin.website.menus.items.store', $menu) : route('admin.website.menu-items.update', (int) $oldItemId),
    ];

    $blank = [
        'id' => null, 'label' => '', 'link_type' => MenuItemLinkType::Page->value, 'page_id' => null, 'route_name' => null,
        'url' => null, 'anchor' => null, 'icon' => null, 'open_new_tab' => false, 'rel_nofollow' => false,
        'visibility' => MenuVisibility::All->value, 'is_enabled' => true, 'parent_id' => null, 'has_children' => false,
        'action' => route('admin.website.menus.items.store', $menu),
    ];
@endphp

@section('header')
    <x-ui.page-header
        :title="$menu->name"
        :subtitle="($location?->label() ?? 'Menu').' · '.app_number($itemCount).' '.\Illuminate\Support\Str::plural('item', $itemCount)"
        icon="bars-3"
        :back="route('admin.website.menus.index')"
        :badge="$menu->is_active ? 'Active' : 'Inactive'"
        :badge-color="$menu->is_active ? 'emerald' : 'slate'"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="link" :href="route('admin.website.menus.link-check', $menu)">
                Link check @if ($brokenCount > 0)<span class="ml-1 rounded-full bg-rose-100 px-1.5 text-2xs font-semibold text-rose-700 dark:bg-rose-500/20 dark:text-rose-300">{{ $brokenCount }}</span>@endif
            </x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" x-on:click="$dispatch('menu-item-edit', {{ \Illuminate\Support\Js::from($blank) }})">Add item</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- ── Tree ─────────────────────────────────────────────────────────── --}}
        <div class="space-y-3 xl:col-span-2">
            @if ($brokenCount > 0)
                <div class="flex items-start gap-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25">
                    <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                    {{ $brokenCount }} {{ \Illuminate\Support\Str::plural('item', $brokenCount) }} point at a target that no longer resolves. Visitors never see a dead link — those items are hidden until the target is fixed.
                </div>
            @endif

            @if ($tree->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="bars-3" title="This menu has no items yet" message="Add a link to a page, a section of the home page, an application route or an external address.">
                        @if ($canCreate)
                            <x-slot:action>
                                <x-ui.button icon="plus" x-on:click="$dispatch('menu-item-edit', {{ \Illuminate\Support\Js::from($blank) }})">Add item</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <div x-data="cmsMenuTree(@js(['url' => route('admin.website.menus.reorder', $menu), 'disabled' => ! $canEdit]))">
                    <p class="sr-only" aria-live="polite" x-text="announcement"></p>
                    <p class="mb-2 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="information-circle" class="h-4 w-4" />
                        Drag by the handle, or use the arrows. An item with children stays at the top level — menus are two levels deep.
                        <span x-show="saving" x-cloak class="ml-auto font-medium text-brand-600 dark:text-brand-400">Saving…</span>
                    </p>

                    <ul data-tree-root class="space-y-3" aria-label="{{ $menu->name }} items">
                        @foreach ($tree as $item)
                            @include('admin.cms.menus.partials.node', ['item' => $item, 'depth' => 0])
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- ── Menu settings ────────────────────────────────────────────────── --}}
        <div>
            <x-ui.card title="Menu settings" icon="cog-6-tooth">
                <form method="POST" action="{{ route('admin.website.menus.update', $menu) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <x-ui.form.input name="name" label="Name" :value="$menu->name" required maxlength="100" :readonly="! $canEdit" />
                    <x-ui.form.textarea name="description" label="Description" :value="$menu->description" :rows="2" maxlength="255" :readonly="! $canEdit" />
                    <x-ui.form.toggle name="is_active" label="Active" description="An inactive menu renders nothing in its slot." :checked="(bool) $menu->is_active" :disabled="! $canEdit" />

                    <dl class="space-y-1 text-xs text-slate-500 dark:text-slate-400">
                        <div class="flex justify-between"><dt>Slot</dt><dd class="font-medium text-slate-700 dark:text-slate-200">{{ $location?->label() }}</dd></div>
                        <div class="flex justify-between"><dt>Key</dt><dd class="font-mono">{{ $menu->slug }}</dd></div>
                    </dl>

                    @if ($canEdit)
                        <div class="flex justify-end">
                            <x-ui.button type="submit" size="sm" icon="check">Save menu</x-ui.button>
                        </div>
                    @endif
                </form>
            </x-ui.card>
        </div>
    </div>

    {{-- ── Item dialog (add + edit) ─────────────────────────────────────────── --}}
    @if ($canCreate || $canEdit)
        <div
            x-data="{
                open: @js($oldItem !== null),
                item: @js($oldItem ?? $blank),
                blank: @js($blank),
                show(detail) { this.item = Object.assign({}, this.blank, detail || {}); this.open = true; },
            }"
            x-on:menu-item-edit.window="show($event.detail)"
            x-on:keydown.escape.window="open = false"
        >
            <div x-show="open" x-cloak class="fixed inset-0 z-modal overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="menu-item-title" style="display: none">
                <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm dark:bg-slate-950/70" x-on:click="open = false" aria-hidden="true"></div>

                <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
                    <form
                        method="POST"
                        x-bind:action="item.action"
                        x-trap.noscroll="open"
                        class="relative w-full overflow-hidden rounded-xl bg-white shadow-modal ring-1 ring-slate-200 sm:max-w-2xl dark:bg-slate-900 dark:ring-slate-800"
                    >
                        @csrf
                        <template x-if="item.id">
                            <input type="hidden" name="_method" value="PUT">
                        </template>
                        <input type="hidden" name="_item_id" x-bind:value="item.id ? item.id : 'new'">

                        <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 id="menu-item-title" class="flex-1 text-base font-semibold text-slate-900 dark:text-white" x-text="item.id ? 'Edit menu item' : 'Add a menu item'"></h2>
                            <x-ui.icon-button icon="x-mark" label="Close" size="sm" x-on:click="open = false" />
                        </div>

                        <div class="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-4">
                            @if ($oldItemId !== null && $errors->any())
                                <div class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">{{ $errors->first() }}</div>
                            @endif

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-ui.form.label for="mi-label" :required="true" class="mb-1.5">Label</x-ui.form.label>
                                    <input id="mi-label" name="label" x-model="item.label" required maxlength="100" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                    <x-ui.form.error for="label" />
                                </div>

                                <div>
                                    <x-ui.form.label for="mi-link-type" :required="true" class="mb-1.5">Links to</x-ui.form.label>
                                    <select id="mi-link-type" name="link_type" x-model="item.link_type" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        @foreach (MenuItemLinkType::cases() as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.form.error for="link_type" />
                                </div>
                            </div>

                            {{-- The target control switches with the link type; only the visible one is posted --}}
                            <div x-show="item.link_type === @js(MenuItemLinkType::Page->value)">
                                <x-ui.form.label for="mi-page" class="mb-1.5">Page</x-ui.form.label>
                                <select id="mi-page" name="page_id" x-model="item.page_id" x-bind:disabled="item.link_type !== @js(MenuItemLinkType::Page->value)" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                    <option value="">Choose a page</option>
                                    @foreach ($pageOptions ?? [] as $pageOption)
                                        <option value="{{ $pageOption->id }}">{{ $pageOption->title }} (/{{ $pageOption->slug }}){{ $pageOption->status?->value !== 'published' ? ' — '.($pageOption->status?->label() ?? 'not live').', hidden until published' : '' }}</option>
                                    @endforeach
                                </select>
                                <x-ui.form.error for="page_id" />
                            </div>

                            <div x-show="item.link_type === @js(MenuItemLinkType::Route->value)" x-cloak>
                                <x-ui.form.label for="mi-route" class="mb-1.5">Route</x-ui.form.label>
                                <select id="mi-route" name="route_name" x-model="item.route_name" x-bind:disabled="item.link_type !== @js(MenuItemLinkType::Route->value)" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                    <option value="">Choose a route</option>
                                    @foreach ($routeOptions ?? [] as $routeName => $routeLabel)
                                        <option value="{{ $routeName }}">{{ $routeName }} — {{ $routeLabel }}</option>
                                    @endforeach
                                </select>
                                <x-ui.form.error for="route_name" />
                            </div>

                            <div x-show="item.link_type === @js(MenuItemLinkType::SectionAnchor->value)" x-cloak>
                                <x-ui.form.label for="mi-anchor" class="mb-1.5">Section</x-ui.form.label>
                                <select id="mi-anchor" name="anchor" x-model="item.anchor" x-bind:disabled="item.link_type !== @js(MenuItemLinkType::SectionAnchor->value)" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                    <option value="">Choose a section anchor</option>
                                    @foreach ($anchorOptions ?? [] as $anchor => $anchorLabel)
                                        <option value="{{ $anchor }}">#{{ $anchor }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Give a section an anchor in its editor to list it here.</p>
                                <x-ui.form.error for="anchor" />
                            </div>

                            <div x-show="item.link_type === @js(MenuItemLinkType::Url->value)" x-cloak>
                                <x-ui.form.label for="mi-url" class="mb-1.5">Address</x-ui.form.label>
                                <input id="mi-url" name="url" x-model="item.url" x-bind:disabled="item.link_type !== @js(MenuItemLinkType::Url->value)" maxlength="500" placeholder="https://… , /path , mailto: , tel:" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Only http, https, mailto, tel or a site-relative /path are accepted.</p>
                                <x-ui.form.error for="url" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-ui.form.label for="mi-icon" class="mb-1.5">Icon</x-ui.form.label>
                                    <select id="mi-icon" name="icon" x-model="item.icon" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        <option value="">No icon</option>
                                        @foreach ($iconNames as $iconName)
                                            <option value="{{ $iconName }}">{{ \Illuminate\Support\Str::headline($iconName) }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.form.error for="icon" />
                                </div>

                                <div>
                                    <x-ui.form.label for="mi-visibility" class="mb-1.5">Shown to</x-ui.form.label>
                                    <select id="mi-visibility" name="visibility" x-model="item.visibility" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        @foreach (MenuVisibility::cases() as $visibilityCase)
                                            <option value="{{ $visibilityCase->value }}">{{ $visibilityCase->label() }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.form.error for="visibility" />
                                </div>

                                <div>
                                    <x-ui.form.label for="mi-parent" class="mb-1.5">Parent</x-ui.form.label>
                                    <select id="mi-parent" name="parent_id" x-model="item.parent_id" x-bind:disabled="item.has_children" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        <option value="">Top level</option>
                                        @foreach ($parentOptions as $parentId => $parentLabel)
                                            <option value="{{ $parentId }}" x-bind:disabled="Number(item.id) === {{ (int) $parentId }}">{{ $parentLabel }}</option>
                                        @endforeach
                                    </select>
                                    <p x-show="item.has_children" class="mt-1 text-xs text-slate-500 dark:text-slate-400">It has children, so it stays at the top level.</p>
                                    <x-ui.form.error for="parent_id" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                @foreach (['open_new_tab' => ['Open in a new tab', 'Adds noopener and noreferrer.'], 'rel_nofollow' => ['nofollow', 'For sponsored or untrusted links.'], 'is_enabled' => ['Enabled', 'Disabled items stay here, hidden from visitors.']] as $flag => [$flagLabel, $flagHelp])
                                    <label class="flex items-start gap-2 rounded-lg p-2 ring-1 ring-slate-200 dark:ring-slate-700">
                                        <input type="hidden" name="{{ $flag }}" value="0">
                                        <input type="checkbox" name="{{ $flag }}" value="1" x-model="item.{{ $flag }}" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                                        <span class="text-sm">
                                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ $flagLabel }}</span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $flagHelp }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-900/60">
                            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                            <x-ui.button type="submit" icon="check"><span x-text="item.id ? 'Save item' : 'Add item'">Save</span></x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection
