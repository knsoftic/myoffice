{{--
    One navigation entry, rendered recursively.

    Expects:
        $item   resolved item from App\Support\Sidebar — label, icon, url, active, children
        $depth  nesting level (0 for top level)

    A parent with children toggles a sub-list; in rail mode it expands the sidebar first so
    the children are readable.
--}}

@php
    $depth = $depth ?? 0;
    $children = $item['children'] ?? [];
    $hasChildren = ! empty($children);
    $active = (bool) ($item['active'] ?? false);
    $label = (string) ($item['label'] ?? '');
    $icon = $item['icon'] ?? null;
    $url = $item['url'] ?? null;

    $rowBase = 'group relative flex w-full items-center gap-3 rounded-lg py-2 text-sm font-medium transition-colors duration-150';
    $rowPad = $depth > 0 ? 'pl-9 pr-2.5' : 'px-2.5';

    $rowState = $active
        ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white';

    $iconState = $active
        ? 'text-brand-600 dark:text-brand-400'
        : 'text-slate-400 group-hover:text-slate-600 dark:text-slate-500 dark:group-hover:text-slate-300';
@endphp

<li class="relative">
    @if ($hasChildren)
        <div x-data="{ open: @js($active) }">
            <button
                type="button"
                x-on:click="if ($store.sidebar.collapsed) { $store.sidebar.expand(); open = true } else { open = ! open }"
                x-bind:aria-expanded="open.toString()"
                title="{{ $label }}"
                class="{{ $rowBase }} {{ $rowPad }} {{ $rowState }} rail-center"
            >
                @if ($active)
                    <span class="absolute inset-y-1.5 left-0 w-0.5 rounded-r-full bg-brand-600 dark:bg-brand-400" aria-hidden="true"></span>
                @endif

                @if ($icon)
                    <x-ui.icon :name="$icon" class="h-[1.125rem] w-[1.125rem] shrink-0 {{ $iconState }}" />
                @endif

                <span class="rail-hide min-w-0 flex-1 truncate text-left">{{ $label }}</span>

                <x-ui.icon
                    name="chevron-down"
                    class="rail-hide h-4 w-4 shrink-0 text-slate-400 transition-transform duration-150 dark:text-slate-500"
                    x-bind:class="open ? 'rotate-180' : ''"
                />
            </button>

            <ul
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="rail-hide mt-0.5 space-y-0.5"
            >
                @foreach ($children as $child)
                    @include('layouts.partials.sidebar-item', ['item' => $child, 'depth' => $depth + 1])
                @endforeach
            </ul>
        </div>
    @elseif ($url)
        <a
            href="{{ $url }}"
            title="{{ $label }}"
            @if ($active) aria-current="page" @endif
            class="{{ $rowBase }} {{ $rowPad }} {{ $rowState }} rail-center"
            x-on:click="$store.sidebar.closeDrawer()"
        >
            @if ($active)
                <span class="absolute inset-y-1.5 left-0 w-0.5 rounded-r-full bg-brand-600 dark:bg-brand-400" aria-hidden="true"></span>
            @endif

            @if ($icon)
                <x-ui.icon :name="$icon" class="h-[1.125rem] w-[1.125rem] shrink-0 {{ $iconState }}" />
            @endif

            <span class="rail-hide min-w-0 flex-1 truncate">{{ $label }}</span>
        </a>
    @else
        {{-- Declared but not linkable (route needs parameters we do not have). --}}
        <span class="{{ $rowBase }} {{ $rowPad }} cursor-default text-slate-400 dark:text-slate-600">
            @if ($icon)
                <x-ui.icon :name="$icon" class="h-[1.125rem] w-[1.125rem] shrink-0" />
            @endif
            <span class="rail-hide min-w-0 flex-1 truncate">{{ $label }}</span>
        </span>
    @endif
</li>
