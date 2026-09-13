{{--
    Customise mode: the hint, the hidden-card tray, and the sticky save bar.

    The layout is per user. It is stored in `users.preferences` through `User::setPreferences()`,
    which writes only to the authenticated row, so two administrators arranging the same dashboard
    never see each other's arrangement.

    Expects: $hidden (Collection<WidgetDescriptor>), $available, $layoutUrl.
--}}

{{-- ── Hint + hidden tray, only while customising ─────────────────────────────────────────── --}}
<div x-show="customising" style="display: none" class="space-y-3">
    <div class="flex flex-wrap items-start gap-3 rounded-xl bg-brand-50/70 p-3 ring-1 ring-brand-200/70 dark:bg-brand-500/5 dark:ring-brand-500/20">
        <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-brand-600 ring-1 ring-brand-200 dark:bg-slate-900 dark:text-brand-400 dark:ring-brand-500/30">
            <x-ui.icon name="adjustments-horizontal" class="h-4 w-4" />
        </span>

        <div class="min-w-0 flex-1 text-sm text-slate-700 dark:text-slate-200">
            <p class="font-medium">Arranging your dashboard</p>
            <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                Drag a card, or use the arrows, to reorder it within its section. The eye switches a
                card off — it keeps its place and stops being measured. Nothing is saved until you
                press Save.
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-ui.button size="sm" variant="ghost" icon="arrow-path" x-on:click="resetLayout()">
                Reset to default
            </x-ui.button>
        </div>
    </div>

    {{-- The cards switched off. Rendered from the server list so the tray needs no JS templating. --}}
    <div
        class="rounded-xl bg-white p-3 ring-1 ring-slate-200/70 shadow-sm dark:bg-slate-900 dark:ring-slate-800"
        x-show="hidden.length > 0"
        style="display: none"
    >
        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Switched off (<span x-text="hidden.length">0</span>)
        </p>

        <div class="flex flex-wrap gap-2">
            @foreach ($available as $descriptor)
                <button
                    type="button"
                    x-show="isHidden(@js($descriptor->key))"
                    style="display: none"
                    x-on:click="toggleHidden(@js($descriptor->key))"
                    class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 py-1 pl-2 pr-2.5 text-xs font-medium text-slate-600 transition-colors hover:bg-brand-50 hover:text-brand-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                    title="Switch {{ $descriptor->title }} back on"
                >
                    <x-ui.icon name="plus" class="h-3.5 w-3.5" />
                    <x-ui.icon :name="$descriptor->icon" class="h-3.5 w-3.5 opacity-70" />
                    {{ $descriptor->title }}
                </button>
            @endforeach
        </div>
    </div>
</div>

{{-- ── Sticky save bar: appears only once something has actually changed ──────────────────── --}}
<div
    x-show="dirty"
    style="display: none"
    {{--
        No x-transition here on purpose: toggling this bar quickly (hide a card, then discard)
        cancels a running Alpine transition, and a cancelled transition rejects a promise nobody
        catches — a console error for a fade nobody asked for.
    --}}
    class="fixed inset-x-0 bottom-0 z-drawer border-t border-slate-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-slate-800 dark:bg-slate-900/95 dark:supports-[backdrop-filter]:bg-slate-900/80"
    role="region"
    aria-label="Unsaved dashboard layout"
>
    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <p class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <x-ui.icon name="information-circle" class="h-4 w-4 text-amber-500" />
            Unsaved layout changes.
            <span class="text-slate-400 dark:text-slate-500">
                <span x-text="visibleCount()"></span> shown, <span x-text="hidden.length"></span> hidden.
            </span>
        </p>

        <div class="flex items-center gap-2">
            <x-ui.button size="sm" variant="ghost" x-on:click="discard()">Discard</x-ui.button>

            <x-ui.button
                size="sm"
                icon="check"
                x-on:click="save()"
                x-bind:disabled="saving"
                x-bind:aria-busy="saving ? 'true' : 'false'"
            >
                <span x-text="saving ? 'Saving…' : 'Save layout'">Save layout</span>
            </x-ui.button>
        </div>
    </div>
</div>
