{{--
    The impact dialog — one instance per page, driven by whichever card was clicked.

    A card dispatches `module-impact` with { name, slug, enable, impactUrl, toggleUrl }; this
    component fetches `admin.modules.impact` (read-only JSON) and shows, before anything is
    written: the modules that depend on this one, the routes that will answer 403, the sidebar
    entries that will disappear — and, in plain words, that NO DATA IS DELETED.

    A reason is demanded for a disable, and when enabled modules depend on this one the cascade
    has to be acknowledged explicitly: the server refuses the disable otherwise (phase-02 §3).
    The dialog is UX — `ModulePolicy` and `ModuleService` decide, on the backend, every time.
--}}

<div
    x-data="{
        name: '',
        slug: '',
        enable: false,
        toggleUrl: '',
        reason: '',
        cascade: false,
        loading: false,
        error: null,
        data: null,

        start(detail) {
            this.name = detail.name || '';
            this.slug = detail.slug || '';
            this.enable = detail.enable === true;
            this.toggleUrl = detail.toggleUrl || '';
            this.reason = '';
            this.cascade = false;
            this.data = null;
            this.error = null;
            this.loading = true;

            this.$dispatch('open-modal', 'module-impact');

            fetch(detail.impactUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (! response.ok) {
                        throw new Error('The impact preview answered ' + response.status + '.');
                    }

                    return response.json();
                })
                .then((payload) => { this.data = payload; })
                .catch((failure) => { this.error = failure.message || 'The impact preview could not be loaded.'; })
                .finally(() => { this.loading = false; });
        },

        get mustAcknowledgeCascade() {
            return ! this.enable && this.data !== null && this.data.requires_cascade === true;
        },
    }"
    x-on:module-impact.window="start($event.detail)"
>
    <x-ui.modal name="module-impact" title="Module impact" icon="puzzle-piece" size="lg">
        {{-- ── What is about to happen ───────────────────────────────────────── --}}
        <div
            class="rounded-lg p-3 ring-1 ring-inset"
            x-bind:class="enable
                ? 'bg-emerald-50/70 text-emerald-800 ring-emerald-100 dark:bg-emerald-500/5 dark:text-emerald-200 dark:ring-emerald-500/20'
                : 'bg-rose-50/70 text-rose-800 ring-rose-100 dark:bg-rose-500/5 dark:text-rose-200 dark:ring-rose-500/20'"
        >
            <p class="text-sm font-semibold tracking-tight">
                <span x-text="enable ? 'Enable' : 'Disable'"></span>
                <span x-text="name"></span>?
            </p>

            <p class="mt-1 text-xs leading-relaxed">
                <span x-show="! enable">
                    Its routes will answer 403 for everyone — Super Admin included — its sidebar entries
                    disappear and every one of its permissions is denied.
                </span>
                <span x-show="enable">
                    Its routes, sidebar entries and permissions become available again to everyone who holds them.
                </span>
            </p>
        </div>

        {{-- ── The guarantee, said plainly and always ────────────────────────── --}}
        <div class="mt-3 flex items-start gap-2 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800/40 dark:ring-slate-700/60">
            <x-ui.icon name="shield-check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                <span class="font-semibold text-slate-900 dark:text-white">No data is deleted.</span>
                Every row this module owns stays exactly where it is — students, payments, projects, files,
                history — and comes back untouched the moment the module is switched on again. Disabling
                closes the doors; it never empties the rooms.
            </p>
        </div>

        {{-- ── Loading ───────────────────────────────────────────────────────── --}}
        <div x-show="loading" class="mt-4 space-y-3" aria-live="polite">
            <p class="text-xs text-slate-500 dark:text-slate-400">Working out what this changes…</p>
            <x-ui.skeleton variant="text" :count="4" />
        </div>

        {{-- ── Could not load the preview ─────────────────────────────────────── --}}
        <div
            x-show="error !== null"
            x-cloak
            class="mt-4 rounded-lg bg-amber-50/70 p-3 text-xs text-amber-800 ring-1 ring-inset ring-amber-100 dark:bg-amber-500/5 dark:text-amber-200 dark:ring-amber-500/20"
        >
            <p class="font-medium">The impact preview could not be loaded.</p>
            <p class="mt-0.5" x-text="error"></p>
            <p class="mt-1">
                You can still continue — the rules are enforced on the server, which refuses a disable that
                would break an enabled module that depends on this one.
            </p>
        </div>

        {{-- ── The detail ────────────────────────────────────────────────────── --}}
        <div x-show="data !== null && ! loading" x-cloak class="mt-4 space-y-3">

            {{-- Dependents that block a plain disable --}}
            <template x-if="! enable && data && data.blocking_dependents.length > 0">
                <div class="rounded-lg bg-amber-50/70 p-3 ring-1 ring-inset ring-amber-100 dark:bg-amber-500/5 dark:ring-amber-500/20">
                    <p class="text-xs font-semibold text-amber-900 dark:text-amber-100">
                        <span x-text="data.blocking_dependents.length"></span>
                        enabled
                        <span x-text="data.blocking_dependents.length === 1 ? 'module depends' : 'modules depend'"></span>
                        on this one
                    </p>

                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <template x-for="dependent in data.blocking_dependents" x-bind:key="dependent.slug">
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-2xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-slate-900 dark:text-amber-200 dark:ring-amber-500/30">
                                <x-ui.icon name="link" class="h-3 w-3" />
                                <span x-text="dependent.name"></span>
                            </span>
                        </template>
                    </div>

                    <p class="mt-2 text-xs leading-relaxed text-amber-800 dark:text-amber-200">
                        Their screens read rows that belong to <span class="font-medium" x-text="name"></span>,
                        so switching it off leaves them half-working. The server refuses this disable unless you
                        accept taking them down too.
                    </p>

                    <template x-if="data.cascade.length > 0">
                        <p class="mt-1.5 text-xs text-amber-800 dark:text-amber-200">
                            Accepting the cascade switches off, in order:
                            <span class="font-medium" x-text="data.cascade.map((one) => one.name).join(' → ')"></span>.
                            Their data is not deleted either.
                        </p>
                    </template>
                </div>
            </template>

            {{-- Dependencies that are already off (the enable case) --}}
            <template x-if="data && data.missing_dependencies.length > 0">
                <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800/40 dark:ring-slate-700/60">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white">
                        This module needs
                        <span x-text="data.missing_dependencies.length"></span>
                        module<span x-text="data.missing_dependencies.length === 1 ? '' : 's'"></span>
                        that <span x-text="data.missing_dependencies.length === 1 ? 'is' : 'are'"></span> still off
                    </p>

                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <template x-for="dependency in data.missing_dependencies" x-bind:key="dependency.slug">
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-2xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-slate-900 dark:text-rose-300 dark:ring-rose-500/30">
                                <x-ui.icon name="exclamation-triangle" class="h-3 w-3" />
                                <span x-text="dependency.name"></span>
                            </span>
                        </template>
                    </div>

                    <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">
                        Switching this module on is allowed, but it will not work fully until those are on too.
                    </p>
                </div>
            </template>

            {{-- What stops working --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800/40 dark:ring-slate-700/60">
                    <p class="text-lg font-semibold tabular-nums tracking-tight text-slate-900 dark:text-white" x-text="data ? data.routes.count : 0"></p>
                    <p class="text-2xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span x-text="enable ? 'routes reopen' : 'routes answer 403'"></span>
                    </p>
                </div>

                <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800/40 dark:ring-slate-700/60">
                    <p class="text-lg font-semibold tabular-nums tracking-tight text-slate-900 dark:text-white" x-text="data ? data.sidebar_items.length : 0"></p>
                    <p class="text-2xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span x-text="enable ? 'menu items return' : 'menu items disappear'"></span>
                    </p>
                </div>

                <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800/40 dark:ring-slate-700/60">
                    <p class="text-lg font-semibold tabular-nums tracking-tight text-slate-900 dark:text-white" x-text="data ? data.permissions.count : 0"></p>
                    <p class="text-2xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span x-text="enable ? 'permissions allowed again' : 'permissions denied'"></span>
                    </p>
                </div>
            </div>

            {{-- The sidebar entries, named --}}
            <template x-if="data && data.sidebar_items.length > 0">
                <div>
                    <p class="text-2xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">
                        Menu items affected
                    </p>

                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <template x-for="(item, index) in data.sidebar_items" x-bind:key="index">
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-2xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                                <span class="uppercase tracking-wide opacity-60" x-text="item.panel"></span>
                                <span x-text="item.label"></span>
                            </span>
                        </template>
                    </div>
                </div>
            </template>

            {{-- The routes, named --}}
            <template x-if="data && data.routes.items.length > 0">
                <details class="rounded-lg bg-slate-50 ring-1 ring-inset ring-slate-200/70 dark:bg-slate-800/40 dark:ring-slate-700/60">
                    <summary class="cursor-pointer px-3 py-2 text-2xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Routes affected (<span x-text="data.routes.count"></span>)
                    </summary>

                    <div class="max-h-40 overflow-y-auto border-t border-slate-200/70 px-3 py-2 dark:border-slate-700/60">
                        <ul class="space-y-1">
                            <template x-for="(route, index) in data.routes.items" x-bind:key="index">
                                <li class="flex items-baseline gap-2 font-mono text-2xs text-slate-500 dark:text-slate-400">
                                    <span class="shrink-0 font-semibold text-slate-400 dark:text-slate-500" x-text="route.methods"></span>
                                    <span class="truncate" x-text="route.uri"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </details>
            </template>
        </div>

        {{-- ── The form that actually does it ─────────────────────────────────── --}}
        <form
            id="module-impact-form"
            method="POST"
            x-bind:action="toggleUrl"
            class="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800"
        >
            @csrf

            <input type="hidden" name="enabled" x-bind:value="enable ? 1 : 0" />

            <div>
                <label for="module-impact-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                    Reason
                    <span class="text-rose-500" x-show="! enable">*</span>
                    <span class="font-normal text-slate-400">(recorded in the audit trail)</span>
                </label>

                <input
                    id="module-impact-reason"
                    type="text"
                    name="reason"
                    maxlength="255"
                    x-model="reason"
                    x-bind:required="! enable"
                    x-bind:minlength="enable ? null : 5"
                    x-bind:placeholder="enable ? 'e.g. going live with this feature area' : 'e.g. not part of this rollout'"
                    class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                />
            </div>

            <label
                x-show="mustAcknowledgeCascade"
                x-cloak
                class="flex items-start gap-2 rounded-lg bg-rose-50/70 p-2.5 text-xs text-rose-800 ring-1 ring-inset ring-rose-100 dark:bg-rose-500/5 dark:text-rose-200 dark:ring-rose-500/20"
            >
                <input
                    type="checkbox"
                    name="cascade"
                    value="1"
                    x-model="cascade"
                    x-bind:required="mustAcknowledgeCascade"
                    class="mt-0.5 h-4 w-4 rounded border-rose-300 text-rose-600 focus:ring-rose-500/40 dark:border-rose-500/40 dark:bg-transparent"
                />
                <span>
                    <span class="font-medium">Yes, switch off the dependent modules too.</span>
                    They go off first, each with its own audit entry and this same reason. Nothing is deleted —
                    every one of them comes back when you switch it on again.
                </span>
            </label>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="md" x-on:click="hide(true)">Cancel</x-ui.button>

            {{-- Two buttons rather than one repainted by Alpine: the variant carries the tone. --}}
            <x-ui.button
                type="submit"
                form="module-impact-form"
                size="md"
                variant="danger"
                icon="eye-slash"
                x-show="! enable"
                x-bind:disabled="loading"
            >
                Disable module
            </x-ui.button>

            <x-ui.button
                type="submit"
                form="module-impact-form"
                size="md"
                variant="success"
                icon="check-circle"
                x-show="enable"
                x-cloak
                x-bind:disabled="loading"
            >
                Enable module
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
