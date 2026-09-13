{{--
    The application sidebar.

    272px on lg+, collapsible to a 76px icon rail (persisted in localStorage), and an
    off-canvas drawer with a backdrop below lg.

    Initial rail state: a stored choice wins, otherwise `appearance.sidebar_collapsed_by_default`.
    The inline head script (layouts/partials/theme-script) resolves the two before first paint and
    publishes the answer on `<html data-sidebar-rail>`; the `x-init` below hands it to the
    `$store.sidebar` store, whose own init only reads localStorage and would expand a rail the
    setting collapsed. Nothing is written to storage here — only the toggle button stores a choice,
    so a browser that never chose keeps following the setting.

    Items come from App\Support\Sidebar::forUser() via the sidebar_items() helper — never
    hardcode the menu here. An item is already filtered by module, route existence and
    permission before it reaches this file.

    Optional variables:
        $panelLabel  chip under the brand (used by layouts/panel.blade.php)
        $panelColor  Tailwind colour token for that chip
        $homeUrl     where the brand links to
--}}

@php
    $groups = sidebar_items();
    $homeUrl = $homeUrl ?? url('/');
    $panelLabel = $panelLabel ?? null;
    $panelColor = $panelColor ?? 'brand';
@endphp

{{-- Backdrop (mobile only) --}}
<div
    x-show="$store.sidebar.drawer"
    x-cloak
    x-transition:enter="transition-opacity ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-on:click="$store.sidebar.closeDrawer()"
    class="fixed inset-0 z-drawer bg-slate-900/50 backdrop-blur-sm lg:hidden dark:bg-slate-950/70"
    aria-hidden="true"
></div>

<aside
    id="app-sidebar"
    x-init="$store.sidebar.collapsed = document.documentElement.dataset.sidebarRail === '1'; document.documentElement.classList.toggle('is-rail', $store.sidebar.collapsed)"
    x-bind:class="{ 'is-open shadow-2xl': $store.sidebar.drawer }"
    x-trap="$store.sidebar.drawer"
    class="shell-sidebar fixed inset-y-0 left-0 z-drawer flex flex-col border-r border-slate-200 bg-white lg:z-topbar dark:border-slate-800 dark:bg-slate-900"
    aria-label="Main navigation"
>
    {{-- Brand --}}
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-slate-200 px-3 dark:border-slate-800">
        <div class="min-w-0 flex-1">
            @include('layouts.partials.brand', ['href' => $homeUrl])
        </div>

        {{-- Close the drawer (mobile only) --}}
        <x-ui.icon-button
            icon="x-mark"
            label="Close navigation"
            size="sm"
            class="lg:hidden"
            x-on:click="$store.sidebar.closeDrawer()"
        />
    </div>

    @if ($panelLabel)
        <div class="rail-hide border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
            <x-ui.badge :color="$panelColor" size="sm" :dot="true">{{ $panelLabel }}</x-ui.badge>
        </div>
    @endif

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 pb-4" aria-label="Sections">
        @forelse ($groups as $group)
            <div class="nav-group">
                @if (filled($group['label'] ?? null))
                    <p class="rail-hide px-2.5 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                        {{ $group['label'] }}
                    </p>
                @else
                    <div class="pt-4"></div>
                @endif

                <ul class="space-y-0.5" role="list">
                    @foreach ($group['items'] ?? [] as $item)
                        @include('layouts.partials.sidebar-item', ['item' => $item, 'depth' => 0])
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="rail-hide px-2 pt-6">
                <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:text-slate-400 dark:ring-slate-700">
                    No sections are available for your account yet.
                </p>
            </div>
        @endforelse
    </nav>

    {{-- Collapse control (lg only) --}}
    <div class="hidden shrink-0 border-t border-slate-200 p-2 lg:block dark:border-slate-800">
        <button
            type="button"
            x-on:click="$store.sidebar.toggle()"
            x-bind:title="$store.sidebar.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            x-bind:aria-label="$store.sidebar.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            class="rail-center group flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <x-ui.icon
                name="chevron-left"
                class="h-[1.125rem] w-[1.125rem] shrink-0 transition-transform duration-200"
                x-bind:class="$store.sidebar.collapsed ? 'rotate-180' : ''"
            />
            <span class="rail-hide">Collapse</span>
        </button>
    </div>
</aside>
