@props([
    'tabs' => [],
    'variant' => 'underline',
])

{{--
    x-ui.tabs — navigation tabs (links) or local panels.

    Link tabs (each tab is a page):
        <x-ui.tabs :tabs="[
            ['label' => 'Profile',  'url' => route('admin.account.profile'),  'active' => true,  'icon' => 'user'],
            ['label' => 'Password', 'url' => route('admin.account.password')],
            ['label' => 'Sessions', 'url' => route('admin.account.sessions'), 'count' => 3],
        ]" />

    Local tabs (no navigation) — wrap your panels in the same x-data:
        <div x-data="uiTabs('details')">
            <x-ui.tabs :tabs="[['label' => 'Details', 'key' => 'details'], ['label' => 'Notes', 'key' => 'notes']]" />
            <div x-show="is('details')">…</div>
        </div>
--}}

@php
    $items = collect($tabs)
        ->filter(fn (mixed $tab): bool => is_array($tab) && filled($tab['label'] ?? null))
        ->values();

    $isPill = $variant === 'pill';
@endphp

@if ($items->isNotEmpty())
    <div
        {{ $attributes->class([
            'relative' => true,
            'border-b border-slate-200 dark:border-slate-800' => ! $isPill,
        ]) }}
        x-on:keydown="typeof onKeydown === 'function' && onKeydown($event)"
    >
        <nav
            class="no-scrollbar -mb-px flex gap-1 overflow-x-auto {{ $isPill ? 'rounded-lg bg-slate-100 p-1 dark:bg-slate-800/60' : '' }}"
            role="tablist"
            aria-label="Tabs"
        >
            @foreach ($items as $tab)
                @php
                    $key = $tab['key'] ?? null;
                    $url = $tab['url'] ?? null;
                    $active = (bool) ($tab['active'] ?? false);
                    $count = $tab['count'] ?? null;

                    $base = 'group inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40';

                    $shape = $isPill
                        ? 'rounded-md px-3 py-1.5'
                        : 'border-b-2 px-3 py-2.5';

                    $idle = $isPill
                        ? 'text-slate-600 hover:bg-white hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-white'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:border-slate-700 dark:hover:text-slate-200';

                    $on = $isPill
                        ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-900 dark:text-brand-300'
                        : 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300';
                @endphp

                @if ($url)
                    <a
                        href="{{ $url }}"
                        role="tab"
                        aria-selected="{{ $active ? 'true' : 'false' }}"
                        class="{{ $base }} {{ $shape }} {{ $active ? $on : $idle }}"
                    >
                        @if (! empty($tab['icon']))
                            <x-ui.icon :name="$tab['icon']" class="h-4 w-4 opacity-70" />
                        @endif

                        {{ $tab['label'] }}

                        @if ($count !== null)
                            <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $count }}</span>
                        @endif
                    </a>
                @else
                    <button
                        type="button"
                        role="tab"
                        @if ($key)
                            x-on:click="select(@js($key))"
                            x-bind:aria-selected="is(@js($key)) ? 'true' : 'false'"
                            x-bind:class="is(@js($key)) ? @js($on) : @js($idle)"
                        @endif
                        class="{{ $base }} {{ $shape }} {{ $key ? '' : ($active ? $on : $idle) }}"
                    >
                        @if (! empty($tab['icon']))
                            <x-ui.icon :name="$tab['icon']" class="h-4 w-4 opacity-70" />
                        @endif

                        {{ $tab['label'] }}

                        @if ($count !== null)
                            <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $count }}</span>
                        @endif
                    </button>
                @endif
            @endforeach

            {{ $slot }}
        </nav>
    </div>
@endif
