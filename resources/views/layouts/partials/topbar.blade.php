{{--
    Sticky topbar: drawer trigger · global search (Ctrl+K) · theme switcher · notifications · account menu.

    Route names are resolved through Route::has() so this file works from day one and starts
    linking the account screens the moment the phase that owns them registers its routes.
--}}

@php
    $topbarUser = auth()->user();

    // Panel prefix drives which account routes we look for, without hardcoding a panel.
    $panelPrefix = 'admin';

    if ($topbarUser && method_exists($topbarUser, 'primaryPanel')) {
        try {
            $panel = $topbarUser->primaryPanel();
            $panelPrefix = $panel instanceof \App\Enums\PanelType ? $panel->routePrefix() : (string) $panel;
        } catch (\Throwable) {
            $panelPrefix = 'admin';
        }
    }

    $firstRoute = static function (array $candidates): ?string {
        foreach ($candidates as $candidate) {
            if (\Illuminate\Support\Facades\Route::has($candidate)) {
                try {
                    return route($candidate);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    };

    $profileUrl = $firstRoute(["{$panelPrefix}.account.profile", 'account.profile', 'profile.edit']);
    $passwordUrl = $firstRoute(["{$panelPrefix}.account.password", 'account.password']);
    $sessionsUrl = $firstRoute(["{$panelPrefix}.account.sessions", 'account.sessions']);
    $logoutUrl = $firstRoute(['logout']);

    // Role chips. getRoleNames() is spatie's cheapest read; labels are nicer when present.
    $roleChips = collect();

    try {
        $roleChips = $topbarUser?->roles
            ->map(fn ($role) => filled($role->label ?? null) ? $role->label : $role->name)
            ->filter()
            ->take(3) ?? collect();
    } catch (\Throwable) {
        $roleChips = collect();
    }

    $avatarUrl = null;

    try {
        $avatarUrl = $topbarUser?->avatar_url;
    } catch (\Throwable) {
        $avatarUrl = null;
    }
@endphp

<header class="sticky top-0 z-topbar border-b border-slate-200 bg-white/80 backdrop-blur supports-[backdrop-filter]:bg-white/70 dark:border-slate-800 dark:bg-slate-900/80 dark:supports-[backdrop-filter]:bg-slate-900/70">
    <div class="flex h-16 items-center gap-2 px-4 sm:gap-3 sm:px-6 lg:px-8">
        {{-- Drawer trigger (mobile) --}}
        <x-ui.icon-button
            icon="menu"
            label="Open navigation"
            variant="secondary"
            class="lg:hidden"
            x-on:click="$store.sidebar.openDrawer()"
            x-bind:aria-expanded="$store.sidebar.drawer.toString()"
            aria-controls="app-sidebar"
        />

        {{-- Global search (placeholder in Phase 1 — the module ships later) --}}
        <div class="relative min-w-0 flex-1 sm:max-w-md">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                <x-ui.icon name="search" class="h-4 w-4" />
            </span>

            <input
                type="search"
                data-global-search
                placeholder="Search…"
                aria-label="Search the system"
                autocomplete="off"
                class="block h-9 w-full rounded-lg border-slate-300 bg-slate-50 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 sm:pr-20 dark:border-slate-700 dark:bg-slate-950/50 dark:text-white dark:placeholder:text-slate-500 dark:focus:bg-slate-950"
            />

            <span class="pointer-events-none absolute inset-y-0 right-0 hidden items-center gap-1 pr-2.5 sm:flex">
                <kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-2xs font-medium text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-500">Ctrl</kbd>
                <kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-2xs font-medium text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-500">K</kbd>
            </span>
        </div>

        <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
            {{-- Theme switcher: light / dark / system --}}
            <div
                class="hidden items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 sm:inline-flex dark:bg-slate-800"
                role="group"
                aria-label="Colour theme"
            >
                @foreach ([['light', 'sun', 'Light'], ['dark', 'moon', 'Dark'], ['system', 'monitor', 'Match system']] as [$value, $themeIcon, $themeLabel])
                    <button
                        type="button"
                        x-on:click="$store.theme.set('{{ $value }}')"
                        x-bind:aria-pressed="$store.theme.is('{{ $value }}').toString()"
                        x-bind:class="$store.theme.is('{{ $value }}')
                            ? 'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400'
                            : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-md transition-colors duration-150"
                        aria-label="{{ $themeLabel }} theme"
                        title="{{ $themeLabel }}"
                    >
                        <x-ui.icon :name="$themeIcon" class="h-4 w-4" />
                    </button>
                @endforeach
            </div>

            {{-- Compact light/dark toggle on very small screens --}}
            <button
                type="button"
                x-on:click="$store.theme.set($store.theme.resolved === 'dark' ? 'light' : 'dark')"
                x-bind:aria-label="$store.theme.resolved === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 sm:hidden dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                aria-label="Switch theme"
            >
                <x-ui.icon name="moon" class="h-[1.125rem] w-[1.125rem]" x-show="$store.theme.resolved !== 'dark'" />
                <x-ui.icon name="sun" class="h-[1.125rem] w-[1.125rem]" x-show="$store.theme.resolved === 'dark'" x-cloak />
            </button>

            {{-- Notifications (placeholder until the notifications module ships) --}}
            <x-ui.icon-button
                icon="bell"
                label="Notifications"
                variant="ghost"
                :badge="true"
            />

            <span class="mx-0.5 hidden h-6 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

            {{-- Account menu --}}
            <x-ui.dropdown align="right" width="w-64" label="Account menu">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="flex max-w-[12rem] items-center gap-2 rounded-lg p-1 pr-2 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
                        x-bind:aria-expanded="open.toString()"
                        aria-haspopup="menu"
                    >
                        <x-ui.avatar :src="$avatarUrl" :name="$topbarUser?->name" size="sm" />

                        <span class="hidden min-w-0 text-left md:block">
                            <span class="block truncate text-xs font-semibold text-slate-900 dark:text-white">
                                {{ $topbarUser?->name }}
                            </span>
                            <span class="block truncate text-2xs text-slate-500 dark:text-slate-400">
                                {{ $roleChips->first() ?? 'Account' }}
                            </span>
                        </span>

                        <x-ui.icon name="chevron-down" class="hidden h-4 w-4 shrink-0 text-slate-400 md:block" />
                    </button>
                </x-slot:trigger>

                <div class="border-b border-slate-200 px-2.5 pb-2.5 pt-1.5 dark:border-slate-700">
                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $topbarUser?->name }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $topbarUser?->email }}</p>

                    @if ($roleChips->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($roleChips as $chip)
                                <x-ui.badge color="brand" size="xs">{{ $chip }}</x-ui.badge>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="py-1">
                    @if ($profileUrl)
                        <x-ui.dropdown-item :href="$profileUrl" icon="user-circle">Profile</x-ui.dropdown-item>
                    @endif

                    @if ($passwordUrl)
                        <x-ui.dropdown-item :href="$passwordUrl" icon="key">Change password</x-ui.dropdown-item>
                    @endif

                    @if ($sessionsUrl)
                        <x-ui.dropdown-item :href="$sessionsUrl" icon="finger-print">Sessions</x-ui.dropdown-item>
                    @endif
                </div>

                @if ($logoutUrl)
                    <div class="border-t border-slate-200 pt-1 dark:border-slate-700">
                        <form method="POST" action="{{ $logoutUrl }}">
                            @csrf
                            <x-ui.dropdown-item type="submit" icon="logout" variant="danger">
                                Log out
                            </x-ui.dropdown-item>
                        </form>
                    </div>
                @endif
            </x-ui.dropdown>
        </div>
    </div>
</header>
