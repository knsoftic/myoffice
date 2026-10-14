{{--
    The topbar bell — unread badge, the newest rows, mark-as-read, live pop-ups (resources/js/notifications.js).

    Expects $panelPrefix from the topbar. The panel is the one being viewed (from the route name, the
    way NotificationController::panel() reads it), falling back to the user's own; its bell routes are
    the shared ones in routes/notifications.php under that panel's prefix, gated by the panel's own
    ability. Where those cannot be reached the old static bell renders unchanged.

    Nothing is counted on the server here: the badge is filled by the bell endpoint right after the
    page loads, so no screen pays a query for it.
--}}

@php
    $bellUser = auth()->user();
    $bellPanels = array_column(\App\Enums\PanelType::cases(), 'value');
    $bellPanel = strtok((string) (request()->route()?->getName() ?? ''), '.') ?: $panelPrefix;
    $bellPanel = in_array($bellPanel, $bellPanels, true) ? $bellPanel : $panelPrefix;
    $bellAbility = $bellPanel === \App\Enums\PanelType::Admin->value ? 'notifications.view_any' : $bellPanel.'_portal.notifications';

    $bellReady = $bellUser !== null
        && \Illuminate\Support\Facades\Route::has($bellPanel.'.notifications.bell')
        && $bellUser->can($bellAbility);

    $bellConfig = $bellReady ? [
        'userId' => (int) $bellUser->getKey(),
        'pageSize' => max(1, (int) setting('support.notification_bell_page_size', 10)),
        'pollSeconds' => 60,
        'urls' => [
            'bell' => route($bellPanel.'.notifications.bell'),
            'read' => route($bellPanel.'.notifications.read', ['notification' => '00000000-0000-0000-0000-000000000000']),
            'readAll' => route($bellPanel.'.notifications.read-all'),
            'go' => route($bellPanel.'.notifications.go', ['notification' => '00000000-0000-0000-0000-000000000000']),
        ],
        'realtime' => ($realtime = \App\Support\Realtime::clientConfig()) === null
            ? null
            : $realtime + ['authEndpoint' => url('/broadcasting/auth')],
    ] : null;

    if ($bellConfig !== null) {
        // The JS swaps the placeholder for a row's id.
        $bellConfig['urls']['read'] = str_replace('00000000-0000-0000-0000-000000000000', '__ID__', $bellConfig['urls']['read']);
        $bellConfig['urls']['go'] = str_replace('00000000-0000-0000-0000-000000000000', '__ID__', $bellConfig['urls']['go']);
    }
@endphp

@if ($bellConfig === null)
    <x-ui.icon-button icon="bell" label="Notifications" variant="ghost" :badge="true" />
@else
    <div class="relative" data-notification-bell x-data="uiBell(@js($bellConfig))"
         x-on:keydown.escape.window="close()" x-on:click.outside="close()">
        <button type="button"
                x-on:click="toggle()"
                x-bind:aria-expanded="open.toString()"
                aria-haspopup="true"
                aria-label="Notifications"
                x-bind:aria-label="label"
                title="Notifications"
                class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">
            <x-ui.icon name="bell" class="h-[1.125rem] w-[1.125rem]" />
            <span x-show="unread > 0" x-cloak x-text="badge"
                  class="absolute -right-0.5 -top-0.5 min-w-[1.125rem] rounded-full bg-rose-500 px-1 text-center text-[10px] font-bold leading-[1.125rem] text-white ring-2 ring-white dark:ring-slate-900"></span>
        </button>

        <div x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             role="dialog" aria-label="Notifications"
             class="fixed inset-x-4 top-16 z-dropdown flex max-h-[70vh] origin-top-right flex-col overflow-hidden rounded-xl bg-white shadow-dropdown ring-1 ring-slate-200 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 dark:bg-slate-900 dark:ring-slate-700">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
                    <span x-show="live" x-cloak class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20" title="New notifications arrive instantly">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Live
                    </span>
                </div>
                <button type="button" x-show="unread > 0" x-cloak x-on:click="markAllRead()"
                        class="text-xs font-medium text-brand-600 hover:text-brand-700 hover:underline dark:text-brand-400 dark:hover:text-brand-300">
                    Mark all as read
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                {{-- First load --}}
                <div x-show="! loaded && ! failed" class="space-y-3 p-4" aria-hidden="true">
                    <div class="h-3 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-700"></div>
                    <div class="h-3 w-full animate-pulse rounded bg-slate-100 dark:bg-slate-800"></div>
                    <div class="h-3 w-1/2 animate-pulse rounded bg-slate-200 dark:bg-slate-700"></div>
                </div>

                <div x-show="failed && ! loaded" x-cloak class="p-6 text-center text-sm text-slate-500 dark:text-slate-400">
                    Could not load notifications.
                    <button type="button" x-on:click="refresh()" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Try again</button>
                </div>

                <div x-show="loaded && rows.length === 0" x-cloak class="flex flex-col items-center px-6 py-10 text-center">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                        <x-ui.icon name="bell" class="h-5 w-5" />
                    </span>
                    <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">You're all caught up</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">New notifications will appear here.</p>
                </div>

                <ul x-show="rows.length > 0" x-cloak role="list" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template x-for="row in rows" x-bind:key="row.id">
                        <li class="group relative" x-bind:class="row.read ? '' : 'bg-brand-50/60 dark:bg-brand-500/5'">
                            <a x-bind:href="goUrl(row)" class="flex gap-3 px-4 py-3 pr-11 transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" x-bind:class="row.read ? 'bg-transparent' : dot(row.level)" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-slate-900 dark:text-white" x-bind:class="row.read ? 'font-normal' : 'font-semibold'" x-text="row.title"></span>
                                    <span x-show="row.body" class="mt-0.5 line-clamp-2 block text-xs text-slate-500 dark:text-slate-400" x-text="row.body"></span>
                                    <span class="mt-1 block text-[11px] text-slate-400 dark:text-slate-500" x-text="ago(row.at)"></span>
                                </span>
                            </a>
                            <button type="button" x-show="! row.read" x-on:click.prevent.stop="markRead(row)"
                                    class="absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:hover:bg-slate-800 dark:hover:text-brand-400"
                                    aria-label="Mark as read" title="Mark as read">
                                <x-ui.icon name="check" class="h-4 w-4" />
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-2.5 text-xs dark:border-slate-700">
                <a href="{{ route($bellPanel.'.notifications.index') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">View all notifications</a>
                @if (\Illuminate\Support\Facades\Route::has($bellPanel.'.notifications.preferences'))
                    <a href="{{ route($bellPanel.'.notifications.preferences') }}" class="text-slate-500 hover:text-slate-700 hover:underline dark:text-slate-400 dark:hover:text-slate-200">Preferences</a>
                @endif
            </div>
        </div>
    </div>
@endif
