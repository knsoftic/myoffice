{{--
    Shared role form — included by admin/roles/create and admin/roles/edit.

    Expects:
        $role               App\Models\Role
        $permissionGroups   array   PermissionMatrix::build()
        $allPermissionNames array   every grantable permission name
        $selected           array   names currently granted
        $panelOptions       array
        $action, $method, $submitLabel

    A system role keeps its identity: `name`, `panel` and `level` are rendered as text plus a
    hidden input carrying the current value, so the submission still passes the required rules
    while UpdateRoleRequest rejects any attempt to change them. Label, description and the matrix
    stay editable — that is the whole point of being able to open a system role at all.
--}}

@php
    use App\Enums\Ability;
    use App\Enums\PanelType;

    $isEdit = $role->exists;
    $isProtected = $isEdit && $role->isProtected();

    // old() wins after a failed submit so a long matrix is never retyped.
    $selectedNames = array_values(array_map(
        static fn (mixed $name): string => (string) $name,
        (array) old('permissions', $selected),
    ));

    $panelValue = old('panel', $role->panel instanceof PanelType ? $role->panel->value : PanelType::Admin->value);
    $levelValue = old('level', $role->level ?? 50);

    $memberCount = $isEdit ? $role->users()->count() : 0;
@endphp

<form
    method="POST"
    action="{{ $action }}"
    x-data="roleMatrix(@js($allPermissionNames), @js($selectedNames))"
    class="space-y-5"
>
    @csrf

    @if (($method ?? 'POST') !== 'POST')
        @method($method)
    @endif

    {{-- ══ Role details ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card title="Role" subtitle="What this role is called and where it lives." icon="shield-check">
                <div class="space-y-4">
                    @if ($isProtected)
                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 px-3 py-2.5 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/25">
                            <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-xs text-amber-900 dark:text-amber-100">
                                This is a <strong class="font-semibold">system role</strong>. Its name, panel and level
                                are frozen and it cannot be deleted. You can still change its label, its description
                                and its permissions.
                            </p>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @if ($isProtected)
                            {{-- Read-only, but still submitted so the required rules pass. --}}
                            <input type="hidden" name="name" value="{{ $role->name }}" />

                            <div>
                                <x-ui.form.label>Name</x-ui.form.label>
                                <p class="mt-1.5 flex h-[2.375rem] items-center gap-2 rounded-lg bg-slate-50 px-3 font-mono text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-950/40 dark:text-slate-300 dark:ring-slate-800">
                                    <x-ui.icon name="lock-closed" class="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                    {{ $role->name }}
                                </p>
                            </div>
                        @else
                            <x-ui.form.input
                                name="name"
                                label="Name"
                                :value="$role->name"
                                required
                                placeholder="Course Coordinator"
                                help="The technical identity used in code and permission checks. Keep it stable."
                            />
                        @endif

                        <x-ui.form.input
                            name="label"
                            label="Display label"
                            :value="$role->label"
                            optional
                            placeholder="Course Coordinator"
                            help="What people see in the interface. Falls back to the name."
                        />
                    </div>

                    <x-ui.form.textarea
                        name="description"
                        label="Description"
                        optional
                        :rows="2"
                        :maxlength="255"
                        :value="$role->description"
                        placeholder="Who should hold this role, and what it is for."
                    />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @if ($isProtected)
                            <input type="hidden" name="panel" value="{{ $panelValue }}" />
                            <input type="hidden" name="level" value="{{ $levelValue }}" />

                            <div>
                                <x-ui.form.label>Panel</x-ui.form.label>
                                <p class="mt-1.5 flex h-[2.375rem] items-center rounded-lg bg-slate-50 px-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-950/40 dark:text-slate-300 dark:ring-slate-800">
                                    {{ PanelType::tryFrom((string) $panelValue)?->label() ?? $panelValue }}
                                </p>
                            </div>

                            <div>
                                <x-ui.form.label>Level</x-ui.form.label>
                                <p class="mt-1.5 flex h-[2.375rem] items-center rounded-lg bg-slate-50 px-3 text-sm tabular-nums text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-950/40 dark:text-slate-300 dark:ring-slate-800">
                                    {{ $levelValue }}
                                </p>
                            </div>
                        @else
                            <x-ui.form.select
                                name="panel"
                                label="Panel"
                                :options="$panelOptions"
                                :selected="$panelValue"
                                required
                                help="Which entry point this role unlocks."
                            />

                            <x-ui.form.input
                                name="level"
                                type="number"
                                label="Level"
                                :value="$levelValue"
                                required
                                min="1"
                                max="65535"
                                inputmode="numeric"
                                help="Lower is more powerful (Super Admin is 1). You can only create roles weaker than your own."
                            />
                        @endif
                    </div>

                    <x-ui.form.toggle
                        name="is_default"
                        label="Default role for this panel"
                        description="New accounts of this panel get this role when nothing else is chosen."
                        :checked="(bool) old('is_default', $role->is_default)"
                    />
                </div>
            </x-ui.card>
        </div>

        {{-- ══ Live summary ══════════════════════════════════════════════════════════ --}}
        <div class="space-y-5">
            <x-ui.card title="Permission matrix" subtitle="Changes apply when you save." icon="key">
                <div class="space-y-4">
                    <div>
                        <p class="text-2xl font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
                            <span x-text="count">{{ count($selectedNames) }}</span>
                            <span class="text-base font-normal text-slate-400 dark:text-slate-500">
                                of <span x-text="total">{{ count($allPermissionNames) }}</span>
                            </span>
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">permissions selected</p>

                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                class="h-full rounded-full bg-brand-500 transition-all duration-150"
                                x-bind:style="`width: ${total === 0 ? 0 : Math.round((count / total) * 100)}%`"
                            ></div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="check" x-on:click="selectAll()">
                            Select all
                        </x-ui.button>

                        <x-ui.button size="sm" variant="secondary" icon="x-mark" x-on:click="clearAll()">
                            Clear all
                        </x-ui.button>

                        <x-ui.button
                            size="sm"
                            variant="ghost"
                            icon="arrow-path"
                            x-on:click="reset()"
                            title="Back to what is saved on the server"
                        >
                            Reset
                        </x-ui.button>
                    </div>

                    <x-ui.form.textarea
                        name="reason"
                        label="Reason for this change"
                        optional
                        :rows="2"
                        :maxlength="255"
                        placeholder="Recorded in the audit trail alongside the granted and revoked permissions."
                    />

                    <x-ui.form.error for="permissions" />
                </div>
            </x-ui.card>

            @if ($isEdit)
                <x-ui.card title="Members" icon="users">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ number_format((float) $memberCount) }}
                        {{ Str::plural('account', $memberCount) }} currently hold this role.
                    </p>

                    <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                        Changing the matrix changes what all of them can do, immediately.
                    </p>

                    @can('users.'.Ability::ViewAny->value)
                        <x-ui.button
                            size="sm"
                            variant="secondary"
                            icon="users"
                            class="mt-3"
                            :href="route('admin.users.index', ['role' => $role->id])"
                        >
                            See who holds it
                        </x-ui.button>
                    @endcan
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- ══ Matrix toolbar ════════════════════════════════════════════════════════════ --}}
    <div class="sticky top-16 z-20 rounded-xl bg-white/95 p-3 shadow-sm ring-1 ring-slate-200/70 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:bg-slate-900/95 dark:ring-slate-800 dark:supports-[backdrop-filter]:bg-slate-900/80">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1 lg:max-w-sm">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                    <x-ui.icon name="search" class="h-4 w-4" />
                </span>

                <input
                    type="search"
                    x-model="search"
                    placeholder="Filter modules and permissions…"
                    aria-label="Filter the permission matrix"
                    class="block w-full rounded-lg border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-500"
                />
            </div>

            <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                <input
                    type="checkbox"
                    x-model="onlySelected"
                    class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800"
                />
                Only modules with something selected
            </label>

            <div class="flex items-center gap-3 lg:ml-auto">
                <span class="text-xs font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                    <span x-text="count">{{ count($selectedNames) }}</span> of
                    <span x-text="total">{{ count($allPermissionNames) }}</span> selected
                </span>

                <x-ui.button type="submit" icon="check" size="sm">{{ $submitLabel }}</x-ui.button>
            </div>
        </div>
    </div>

    @include('admin.roles.partials.matrix', [
        'permissionGroups' => $permissionGroups,
        'selectedNames' => $selectedNames,
        'readOnly' => false,
    ])

    {{-- ══ Actions ═══════════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col-reverse items-stretch gap-2 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-end dark:border-slate-800">
        <x-ui.button variant="secondary" :href="route('admin.roles.index')">Cancel</x-ui.button>
        <x-ui.button type="submit" icon="check">{{ $submitLabel }}</x-ui.button>
    </div>
</form>

@push('scripts')
    <script>
        /*
         * roleMatrix — the permission grid's only behaviour.
         *
         * Registered on `alpine:init` rather than at parse time: app.js is an ES module, so it
         * runs after this classic script, and Alpine does not exist yet when this file executes.
         * `alpine:init` fires from inside Alpine.start(), which is exactly the window we need.
         *
         * `selected` is an object keyed by permission name (not an array) so every checkbox reads
         * its own state in O(1) — with ~600 permissions on screen, an array membership test per
         * checkbox per keystroke is the difference between instant and sluggish.
         *
         * Bulk controls receive their target names through a `data-names` attribute (space
         * separated — permission names never contain a space), which keeps the server-rendered
         * markup the single source of truth for what a row or column covers.
         */
        document.addEventListener('alpine:init', () => {
            if (! window.Alpine) {
                return;
            }

            window.Alpine.data('roleMatrix', (all = [], held = []) => ({
                search: '',
                onlySelected: false,
                selected: {},

                init() {
                    this.selected = this.build(held);
                },

                /** name => bool for every known permission, so each key is reactive from the start. */
                build(names) {
                    const on = new Set(names);
                    const map = {};

                    all.forEach((name) => {
                        map[name] = on.has(name);
                    });

                    return map;
                },

                get total() {
                    return all.length;
                },

                get count() {
                    let total = 0;

                    for (const name in this.selected) {
                        if (this.selected[name]) {
                            total++;
                        }
                    }

                    return total;
                },

                /** "a.b c.d" => ['a.b', 'c.d'] */
                names(raw) {
                    return String(raw ?? '').split(' ').filter((name) => name !== '');
                },

                /**
                 * Which permissions a bulk control covers: its own data-names, or the nearest
                 * ancestor that has one. Letting the row and the group wrapper own the list keeps
                 * a single copy of it in the HTML instead of one per control.
                 */
                targets(el) {
                    return el.dataset.names ?? (el.closest('[data-names]')?.dataset.names ?? '');
                },

                /** How much of a set is on: 'all', 'some' or 'none'. Drives the tri-state boxes. */
                state(raw) {
                    const names = this.names(raw);

                    if (names.length === 0) {
                        return 'none';
                    }

                    const on = this.onCount(raw);

                    if (on === 0) {
                        return 'none';
                    }

                    return on === names.length ? 'all' : 'some';
                },

                onCount(raw) {
                    return this.names(raw).reduce((total, name) => total + (this.selected[name] ? 1 : 0), 0);
                },

                setMany(raw, value) {
                    this.names(raw).forEach((name) => {
                        this.selected[name] = Boolean(value);
                    });
                },

                selectAll() {
                    all.forEach((name) => {
                        this.selected[name] = true;
                    });
                },

                clearAll() {
                    all.forEach((name) => {
                        this.selected[name] = false;
                    });
                },

                /** Back to what the server sent — an undo for the whole grid. */
                reset() {
                    this.selected = this.build(held);
                },

                /**
                 * Is this row visible under the current filter? The row carries its own haystack
                 * and name list, so no second copy of the grid lives in JavaScript.
                 */
                visible(el) {
                    if (this.onlySelected && this.state(el.dataset.names) === 'none') {
                        return false;
                    }

                    const term = this.search.trim().toLowerCase();

                    if (term === '') {
                        return true;
                    }

                    return (el.dataset.search ?? '').includes(term);
                },

                /**
                 * A group is worth showing while at least one of its rows is. Asking the rows
                 * rather than storing a second, group-sized haystack in the HTML saved ~60 KB on
                 * the page; the query is cheap because it only ever walks one card.
                 */
                groupVisible(el) {
                    return Array.from(el.querySelectorAll('tbody tr[data-search]'))
                        .some((row) => this.visible(row));
                },
            }));
        });
    </script>
@endpush
