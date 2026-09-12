{{--
    Permission matrix editor.

    Lives inside the `roleMatrix()` Alpine scope opened by admin/roles/partials/form, and posts a
    flat `permissions[]` of permission names — names, never ids, so a reseed that renumbers the
    table cannot silently change what a role grants.

    Shape: one card per ModuleGroup, one row per module, one column per ability. The ability
    columns are computed **per group** (PermissionMatrix), because the Ability enum has 18 cases
    and the portal prefixes declare free-form abilities of their own — one global header would be
    forty-odd mostly-empty columns.

    Bulk controls are native checkboxes: `checked` when a whole set is on, `indeterminate` when
    only part of it is (through x-effect, since `indeterminate` is a DOM property, not an
    attribute). Nothing reloads while toggling; the server sees the matrix once, on submit.

    A note on the formatting below: the cell markup is deliberately written flush against the left
    margin on single lines. The grid emits ~2,100 cells, and indenting them to match the
    surrounding nesting added 1.3 MB of pure whitespace to the response — more than half the page.
    The repeated parts are explained in comments instead of by indentation.

    Expects:
        $permissionGroups  array   output of PermissionMatrix::build()
        $selectedNames     array   permission names currently granted
        $readOnly          bool    render the grid without inputs
--}}

@php
    $readOnly = $readOnly ?? false;

    // O(1) lookup per cell instead of in_array() over every granted name.
    $selectedLookup = array_fill_keys($selectedNames, true);

    /**
     * Lowercased haystack for the live filter. Permission names already contain the slug and the
     * ability, so only the human module name has to be added.
     */
    $haystack = static fn (array $module): string => mb_strtolower(
        $module['name'].' '.implode(' ', $module['names'])
    );

    /**
     * Tri-state bulk checkbox. It reads its target set from its own data-names, or — when it has
     * none — from the nearest ancestor carrying one (the <tr>, or the group wrapper), so the big
     * name lists are emitted exactly once each.
     */
    $bulk = 'x-bind:checked="state(targets($el)) === \'all\'" '
        .'x-effect="$el.indeterminate = state(targets($el)) === \'some\'" '
        .'x-on:change="setMany(targets($el), $el.checked)"';
@endphp

@push('styles')
    {{--
        Two colour-free helpers, so ~2,100 cells do not each carry a 180-character class list.
        Colour, size, border and the focus ring already come from the base layer's
        [type='checkbox'] and input:focus-visible rules — which is why nothing here names a palette
        and the theme stays the single source of truth.
    --}}
    <style>
        /*
         * `thead .mx-cell` is not redundant: x-ui.table's header row carries
         * [&>*]:text-left, whose compiled selector is one step more specific than a bare class,
         * so a plain `text-center` on a <th> would silently lose. Matching its specificity and
         * coming later in the cascade is what actually centres the ability columns.
         */
        .mx-cell,
        thead .mx-cell { text-align: center; }

        /* The marker for "this module does not declare this ability" — no element needed. */
        .mx-cell-empty::after { content: "\00B7"; opacity: .3; }

        /* Size, colour, border and focus ring come from the base layer; only these two differ. */
        .mx-box { border-radius: .25rem; cursor: pointer; }
    </style>
@endpush

@if ($permissionGroups === [])
    <x-ui.card>
        <x-ui.empty-state
            icon="key"
            title="No permissions are registered"
            message="Permissions come from App\Support\PermissionRegistry and are written by the seeder. Run the seeders and this grid fills itself."
            :compact="true"
        />
    </x-ui.card>
@else
    <div class="space-y-5">
        @foreach ($permissionGroups as $group)
            @php
                // Per-column name lists, so a column header can flip its whole column.
                $columns = [];

                foreach ($group['abilities'] as $ability) {
                    $names = [];

                    foreach ($group['modules'] as $module) {
                        if (isset($module['cells'][$ability['value']])) {
                            $names[] = (string) $module['cells'][$ability['value']]->name;
                        }
                    }

                    $columns[$ability['value']] = implode(' ', $names);
                }

                $groupNames = [];

                foreach ($group['modules'] as $module) {
                    $groupNames = array_merge($groupNames, $module['names']);
                }

                $groupNames = implode(' ', array_unique($groupNames));
            @endphp

            {{--
                x-show is edit-only: the read-only grid renders outside the roleMatrix() scope,
                where an unresolvable expression would evaluate falsy and hide everything.
            --}}
            <div data-group="{{ $group['key'] }}" data-names="{{ $groupNames }}" @unless ($readOnly) x-show="groupVisible($el)" @endunless>
                <x-ui.card
                    :padded="false"
                    :title="$group['label']"
                    :subtitle="count($group['modules']).' '.Str::plural('module', count($group['modules'])).' · '.$group['total'].' '.Str::plural('permission', $group['total'])"
                >
                    @unless ($readOnly)
                        <x-slot:actions>
                            <span class="hidden text-2xs text-slate-400 tabular-nums sm:inline dark:text-slate-500">
                                <span x-text="onCount(targets($el))">0</span> / {{ $group['total'] }} selected
                            </span>

                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                                <input type="checkbox" class="mx-box" {!! $bulk !!} aria-label="Select every {{ $group['label'] }} permission" />
                                Whole group
                            </label>
                        </x-slot:actions>
                    @endunless

                    <x-ui.table max-height="28rem" :flush="true" :dense="true" :hover="false">
                        <x-slot:head>
                            {{-- The corner cell stays put on both axes while the grid scrolls. --}}
                            <th scope="col" class="sticky left-0 z-20 min-w-[13rem] bg-slate-50/95 px-4 py-3 backdrop-blur dark:bg-slate-900/95">Module</th>
{{-- One header per ability, each with the toggle for its whole column. --}}
@foreach ($group['abilities'] as $ability)
<th scope="col" class="mx-cell whitespace-nowrap px-3 py-2"><span class="block text-2xs">{{ $ability['label'] }}</span>@unless ($readOnly)<label class="mt-1 inline-flex cursor-pointer items-center justify-center" title="Toggle the whole {{ $ability['label'] }} column"><input type="checkbox" class="mx-box" data-names="{{ $columns[$ability['value']] }}" {!! $bulk !!} aria-label="Toggle the whole {{ $ability['label'] }} column" /></label>@endunless</th>
@endforeach
                        </x-slot:head>
{{-- One row per module: sticky name cell, then a cell per ability column. --}}
@foreach ($group['modules'] as $module)
<tr data-names="{{ implode(' ', $module['names']) }}" data-search="{{ $haystack($module) }}" @unless ($readOnly) x-show="visible($el)" @endunless>
<th scope="row" class="sticky left-0 z-10 min-w-[13rem] bg-white px-4 py-2.5 text-left font-normal dark:bg-slate-900">
    <div class="flex items-start gap-2.5">
        @unless ($readOnly)
            {{-- No data-names of its own: targets() walks up to the <tr>. --}}
            <input type="checkbox" class="mx-box mt-0.5" {!! $bulk !!} aria-label="Toggle every {{ $module['name'] }} permission" />
        @endunless

        <span class="min-w-0">
            <span class="flex items-center gap-1.5">
                @if ($module['icon'])
                    <x-ui.icon :name="$module['icon']" class="h-3.5 w-3.5 shrink-0 text-slate-400 dark:text-slate-500" />
                @endif

                <span class="truncate text-xs font-medium text-slate-800 dark:text-slate-100">{{ $module['name'] }}</span>

                {{-- A `title` attribute raises no tooltip on <svg>, so it goes on a wrapper. --}}
                @if ($module['is_core'])
                    <span class="inline-flex shrink-0" title="Core module — it can never be switched off">
                        <x-ui.icon name="lock-closed" class="h-3 w-3 text-amber-500" />
                    </span>
                @elseif (! $module['is_enabled'])
                    <span class="inline-flex shrink-0" title="Module disabled — these abilities are denied to everyone, Super Admin included, until it is switched back on">
                        <x-ui.icon name="eye-slash" class="h-3 w-3 text-rose-500" />
                    </span>
                @endif
            </span>

            <span class="block truncate font-mono text-2xs text-slate-400 dark:text-slate-500">{{ $module['slug'] }}</span>
        </span>
    </div>
</th>
@foreach ($group['abilities'] as $ability)
@php
    $permission = $module['cells'][$ability['value']] ?? null;
    $held = $permission !== null && isset($selectedLookup[(string) $permission->name]);
@endphp
@if ($permission === null)
<td class="mx-cell mx-cell-empty"></td>
@elseif ($readOnly)
<td class="mx-cell">@if ($held)<span class="inline-flex" title="{{ $permission->name }}"><x-ui.icon name="check" class="mx-auto h-4 w-4 text-emerald-500" /></span>@endif</td>
@else
<td class="mx-cell"><input type="checkbox" class="mx-box" name="permissions[]" value="{{ $permission->name }}" @checked($held) x-model="selected['{{ $permission->name }}']" title="{{ $permission->label ?: $permission->name }}" /></td>
@endif
@endforeach
</tr>
@endforeach
                    </x-ui.table>
                </x-ui.card>
            </div>
        @endforeach
    </div>
@endif
