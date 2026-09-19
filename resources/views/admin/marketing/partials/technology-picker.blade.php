{{--
    The technology multi-select with inline create (phase-04 §8.2 "multi-select combobox with inline create
    when the user holds technologies.create"; services and portfolio items).

    @include('admin.marketing.partials.technology-picker', [
        'choices' => $technologyOptions,   // array<int, string> id => name
        'selected' => [3, 7],              // ordered ids currently attached (old() already applied)
        'name' => 'technology_ids',        // posts technology_ids[] in the order chosen
        'help' => 'The chips appear on the page in this order.',
    ])

    Inline create POSTs JSON {name, is_active: 1} to admin.technologies.store with Accept: application/json and
    expects 201/200 JSON carrying the new row as {id, name} (top level, or under `technology` / `data`). The
    route's can:technologies.create re-checks the permission; a refusal is shown under the search box.
--}}

@php
    $fieldName = $name ?? 'technology_ids';
    $canCreateTechnology = (bool) auth()->user()?->can('technologies.create') && \Illuminate\Support\Facades\Route::has('admin.technologies.store');
    $choiceList = collect($choices ?? [])->map(static fn ($label, $id): array => ['id' => (int) $id, 'name' => (string) $label])->values()->all();
    $selectedIds = array_values(array_filter(array_map('intval', (array) ($selected ?? []))));
@endphp

<div
    x-data="{
        choices: @js($choiceList),
        selected: @js($selectedIds),
        search: '',
        creating: false,
        createError: '',
        get filtered() {
            const term = this.search.trim().toLowerCase();
            return this.choices.filter((choice) => term === '' || choice.name.toLowerCase().includes(term));
        },
        get exactMatch() {
            const term = this.search.trim().toLowerCase();
            return this.choices.some((choice) => choice.name.toLowerCase() === term);
        },
        nameOf(id) { return (this.choices.find((choice) => choice.id === id) || {}).name || ('#' + id); },
        toggle(id) {
            this.selected = this.selected.includes(id) ? this.selected.filter((item) => item !== id) : [...this.selected, id];
            this.$dispatch('input');
        },
        async create() {
            const name = this.search.trim();
            if (name === '' || this.creating || ! @js($canCreateTechnology)) return;
            this.creating = true;
            this.createError = '';
            try {
                const response = await fetch(@js($canCreateTechnology ? route('admin.technologies.store') : ''), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ name, is_active: 1 }),
                });
                const body = await response.json().catch(() => ({}));
                if (! response.ok) { throw new Error((body.errors && Object.values(body.errors).flat()[0]) || body.message || 'The technology was not created.'); }
                const record = body.technology || body.data || body;
                if (! record || ! record.id) { throw new Error('The technology was created but could not be selected. Reload the page.'); }
                this.choices.push({ id: Number(record.id), name: String(record.name || name) });
                this.selected.push(Number(record.id));
                this.search = '';
                this.$dispatch('input');
            } catch (error) {
                this.createError = error.message;
            } finally {
                this.creating = false;
            }
        },
    }"
>
    <template x-for="id in selected" :key="'tech-' + id">
        <input type="hidden" name="{{ $fieldName }}[]" x-bind:value="id">
    </template>
    <template x-if="selected.length === 0">
        <input type="hidden" name="{{ $fieldName }}" value="">
    </template>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <label for="technology-search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Technologies used</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                    <x-ui.icon name="magnifying-glass" class="h-4 w-4" />
                </span>
                <input
                    id="technology-search"
                    type="search"
                    x-model="search"
                    x-on:keydown.enter.prevent="filtered.length === 1 ? toggle(filtered[0].id) : (! exactMatch ? create() : null)"
                    placeholder="{{ $canCreateTechnology ? 'Search, or type a new technology and press Enter…' : 'Search technologies…' }}"
                    data-dirty-ignore
                    autocomplete="off"
                    class="block w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                >
            </div>

            <ul class="mt-3 grid max-h-80 grid-cols-1 gap-1.5 overflow-y-auto sm:grid-cols-2" role="listbox" aria-multiselectable="true" aria-label="Technologies">
                <template x-for="choice in filtered" :key="'choice-' + choice.id">
                    <li>
                        <button
                            type="button"
                            role="option"
                            x-on:click="toggle(choice.id)"
                            x-bind:aria-selected="selected.includes(choice.id) ? 'true' : 'false'"
                            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm ring-1 ring-inset transition"
                            x-bind:class="selected.includes(choice.id)
                                ? 'bg-brand-50 text-brand-800 ring-brand-300 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/40'
                                : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800'"
                        >
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded border" x-bind:class="selected.includes(choice.id) ? 'border-brand-600 bg-brand-600 text-white dark:border-brand-400 dark:bg-brand-500' : 'border-slate-300 dark:border-slate-600'">
                                <svg x-show="selected.includes(choice.id)" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            </span>
                            <span class="truncate" x-text="choice.name"></span>
                        </button>
                    </li>
                </template>
            </ul>

            <p x-show="choices.length === 0" class="mt-3 text-sm text-slate-500 dark:text-slate-400">No technologies exist yet.</p>

            @if ($canCreateTechnology)
                <div x-show="search.trim() !== '' && ! exactMatch" x-cloak class="mt-3">
                    <x-ui.button variant="secondary" size="sm" icon="plus" x-on:click="create()" x-bind:disabled="creating">
                        <span x-text="creating ? 'Creating…' : 'Create “' + search.trim() + '”'"></span>
                    </x-ui.button>
                    <p x-show="createError" x-text="createError" class="mt-1.5 text-xs font-medium text-rose-600 dark:text-rose-400"></p>
                </div>
            @endif
            <x-ui.form.error :for="$fieldName" />
        </div>

        <div>
            <p class="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Selected <span class="text-slate-400" x-text="'(' + selected.length + ')'"></span></p>
            <div class="flex flex-wrap gap-1.5">
                <template x-for="id in selected" :key="'chip-' + id">
                    <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 py-0.5 pl-2 pr-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <span x-text="nameOf(id)"></span>
                        <button type="button" x-on:click="toggle(id)" class="inline-flex h-4 w-4 items-center justify-center rounded text-slate-400 hover:text-rose-600 dark:hover:text-rose-400" x-bind:aria-label="'Remove ' + nameOf(id)">
                            <x-ui.icon name="x-mark" class="h-3 w-3" />
                        </button>
                    </span>
                </template>
                <p x-show="selected.length === 0" class="text-xs text-slate-400 dark:text-slate-500">None selected.</p>
            </div>
            @if (filled($help ?? null))
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
            @endif
        </div>
    </div>
</div>
