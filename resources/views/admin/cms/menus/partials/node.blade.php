{{--
    One menu tree node (included by admin/cms/menus/show.blade.php).

    Expects: $item (MenuItem, `children` loaded when top level), $depth (0|1), $resolvedUrls, $brokenReasons,
             $canEdit, $canToggle, $canDelete. Runs inside the cmsMenuTree Alpine scope.
--}}

@php
    use App\Enums\Cms\MenuItemLinkType;
    use App\Enums\Cms\MenuVisibility;

    $linkType = $item->link_type instanceof MenuItemLinkType ? $item->link_type : MenuItemLinkType::tryFrom((string) $item->link_type);
    $visibility = $item->visibility instanceof MenuVisibility ? $item->visibility : MenuVisibility::tryFrom((string) $item->visibility);
    $url = $resolvedUrls[$item->id] ?? null;
    $broken = $brokenReasons[$item->id] ?? null;
    $children = $depth === 0 && $item->relationLoaded('children') ? $item->children : collect();

    $payload = [
        'id' => $item->id,
        'label' => $item->label,
        'link_type' => $linkType?->value,
        'page_id' => $item->page_id,
        'route_name' => $item->route_name,
        'url' => $item->url,
        'anchor' => $item->anchor,
        'icon' => $item->icon,
        'open_new_tab' => (bool) $item->open_new_tab,
        'rel_nofollow' => (bool) $item->rel_nofollow,
        'visibility' => $visibility?->value,
        'is_enabled' => (bool) $item->is_enabled,
        'parent_id' => $item->parent_id,
        'has_children' => $children->isNotEmpty(),
        'action' => route('admin.website.menu-items.update', $item),
    ];
@endphp

<li
    data-node-id="{{ $item->id }}"
    data-depth="{{ $depth }}"
    x-on:dragstart="dragStart($event)"
    x-on:dragover.prevent="dragOver($event)"
    x-on:dragend="dragEnd()"
    class="group/node"
>
    <div @class([
        'flex flex-col gap-2 rounded-lg bg-white p-3 ring-1 sm:flex-row sm:items-center dark:bg-slate-900',
        'ring-rose-300 dark:ring-rose-500/40' => $broken,
        'ring-slate-200 dark:ring-slate-700' => ! $broken,
        'opacity-60' => ! $item->is_enabled,
    ])>
        <div class="flex min-w-0 flex-1 items-start gap-2">
            <button
                type="button"
                x-on:pointerdown="arm($event)"
                @disabled(! $canEdit)
                aria-roledescription="drag handle"
                aria-label="Reorder {{ $item->label }}"
                @class([
                    'mt-0.5 inline-flex h-7 w-5 shrink-0 items-center justify-center rounded text-slate-400 dark:text-slate-500',
                    'cursor-grab hover:bg-slate-100 active:cursor-grabbing dark:hover:bg-slate-800' => $canEdit,
                    'cursor-not-allowed opacity-40' => ! $canEdit,
                ])
            >
                <x-ui.icon name="ellipsis-vertical" class="h-4 w-4" />
            </button>

            @if (filled($item->icon))
                <x-ui.icon :name="(string) $item->icon" class="mt-1 h-4 w-4 shrink-0 text-slate-500 dark:text-slate-400" />
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $item->label }}</span>
                    @if ($linkType)
                        <x-ui.badge :color="$linkType->color()" size="sm">{{ $linkType->label() }}</x-ui.badge>
                    @endif
                    @if ($item->open_new_tab)
                        <x-ui.badge color="slate" variant="outline" size="sm" icon="arrow-top-right-on-square">New tab</x-ui.badge>
                    @endif
                    @if ($item->rel_nofollow)
                        <x-ui.badge color="slate" variant="outline" size="sm">nofollow</x-ui.badge>
                    @endif
                    @if ($visibility && $visibility !== MenuVisibility::All)
                        <x-ui.badge :color="$visibility->color()" size="sm" icon="eye">{{ $visibility->label() }}</x-ui.badge>
                    @endif
                    @unless ($item->is_enabled)
                        <x-ui.badge color="slate" variant="outline" size="sm" icon="eye-slash">Disabled</x-ui.badge>
                    @endunless
                    @if ($broken)
                        <x-ui.badge color="rose" size="sm" icon="exclamation-triangle">{{ $broken }}</x-ui.badge>
                    @endif
                </div>
                <p class="mt-0.5 truncate font-mono text-2xs text-slate-500 dark:text-slate-400">
                    {{ $url ?? ($linkType === MenuItemLinkType::None ? 'Label only — opens its children' : 'No resolvable address') }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1 sm:justify-end">
            @if ($canEdit)
                <x-ui.icon-button icon="chevron-up" size="xs" label="Move {{ $item->label }} up" x-on:click="move($el, -1)" />
                <x-ui.icon-button icon="chevron-down" size="xs" label="Move {{ $item->label }} down" x-on:click="move($el, 1)" />
                @if ($depth === 0)
                    <x-ui.icon-button icon="chevron-right" size="xs" label="Nest {{ $item->label }} under the item above" x-on:click="indent($el)" />
                @else
                    <x-ui.icon-button icon="chevron-left" size="xs" label="Move {{ $item->label }} to the top level" x-on:click="outdent($el)" />
                @endif
                <x-ui.icon-button icon="pencil" size="xs" label="Edit {{ $item->label }}" x-on:click="$dispatch('menu-item-edit', {{ \Illuminate\Support\Js::from($payload) }})" />
            @endif

            @if ($canToggle)
                <form method="POST" action="{{ route('admin.website.menu-items.toggle', $item) }}">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $item->is_enabled ? 0 : 1 }}">
                    <x-ui.icon-button type="submit" size="xs" :icon="$item->is_enabled ? 'eye' : 'eye-slash'" :label="($item->is_enabled ? 'Disable ' : 'Enable ').$item->label" />
                </form>
            @endif

            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.website.menu-items.destroy', $item)"
                    :title="'Delete '.$item->label.'?'"
                    :message="$children->isNotEmpty()
                        ? 'Its '.$children->count().' child '.\Illuminate\Support\Str::plural('link', $children->count()).' are deleted with it. Consider disabling it instead.'
                        : 'It disappears from the menu. Consider disabling it if it may come back.'"
                    confirm-label="Delete link"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" size="xs" label="Delete {{ $item->label }}" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </div>
    </div>

    @if ($depth === 0)
        <ul
            data-tree-children
            class="ml-6 mt-2 min-h-[0.5rem] space-y-2 border-l-2 border-dashed border-slate-200 pl-3 empty:min-h-[0.75rem] dark:border-slate-700"
            aria-label="Children of {{ $item->label }}"
        >
            @foreach ($children as $child)
                @include('admin.cms.menus.partials.node', ['item' => $child, 'depth' => 1])
            @endforeach
        </ul>
    @endif
</li>
