@props([
    'isEmpty' => false,
    'dense' => false,
    'hover' => true,
    'maxHeight' => null,
    'flush' => false,
    'loading' => false,
    'loadingRows' => 5,
    'columns' => null,
    'selectable' => false,
    'selectionName' => 'ids',
    'selectionLabel' => 'row',
    'caption' => null,
])

{{--
    x-ui.table — responsive table shell.

        <x-ui.table :is-empty="$users->isEmpty()">
            <x-slot:head>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Name</x-ui.th-sortable>
                <th class="px-4 py-3 text-left font-semibold">Roles</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($users as $user)
                <tr>
                    <td class="px-4 py-3">{{ $user->name }}</td>
                    …
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="users" title="No users yet" />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$users" label="users" />
            </x-slot:footer>
        </x-ui.table>

    The wrapper scrolls horizontally on its own, so the page body never does.
    Pass max-height="60vh" to make the header stick while the body scrolls vertically.

    ---------------------------------------------------------------------------------------------
    loading — skeleton rows instead of content (carryover T18)
    ---------------------------------------------------------------------------------------------

    Two shapes, because a list is loading for two different reasons:

    1.  A **boolean**, for a server-rendered table whose rows are not available yet (a widget
        rendered before its data call, a deliberate placeholder):

            <x-ui.table :loading="true" :loading-rows="6" :columns="5"> … </x-ui.table>

        The skeleton replaces the body, and the empty state is suppressed — "no rows" and "not
        loaded yet" must never look the same.

    2.  An **Alpine expression** (any string that is not a boolean literal), for a table that is
        about to be replaced in the browser. This is what an auto-submitting filter bar needs: the
        old rows are stale the moment a filter changes, so they are swapped for skeleton rows
        while the new page loads, rather than sitting there looking current.

            <div x-data="{ navigating: false }"
                 x-on:submit.window="if ($event.target?.method === 'get') navigating = true">
                <x-ui.filter-bar … />
                <x-ui.table loading="navigating" :is-empty="$roles->isEmpty()"> … </x-ui.table>
            </div>

        Both tbodies are rendered and swapped by `x-show`, so nothing depends on JavaScript to
        show the real rows: with Alpine unavailable the real body is visible and the skeleton is
        hidden by `x-cloak`.

    Either way `aria-busy` is set on the wrapper, so a screen reader is told the region is
    updating instead of reading a table of grey bars. The skeleton's column count is taken from
    the `columns` prop, or counted from the `head` slot when it is not given.

    ---------------------------------------------------------------------------------------------
    selectable — bulk selection (carryover T18)
    ---------------------------------------------------------------------------------------------

    `selectable` adds the leading checkbox column, the select-all control in the header (with a
    real indeterminate state), and a selection bar that appears above the table and renders the
    `bulk` slot. The component owns the state; the row cell and the action itself belong to the
    view, because only it knows what the rows are and what may be done to them:

        <x-ui.table :selectable="true" selection-label="module" :is-empty="$modules->isEmpty()">
            <x-slot:head>
                <x-ui.th-sortable column="name" …>Module</x-ui.th-sortable>
                …
            </x-slot:head>

            // The bulk slot lives inside this component's Alpine scope, so `selected` is
            // in scope and the form can post exactly what is ticked.
            <x-slot:bulk>
                <form method="POST" action="{{ route('admin.modules.bulk-toggle') }}">
                    @csrf
                    <template x-for="pickedId in selected" :key="pickedId">
                        <input type="hidden" name="ids[]" x-bind:value="pickedId">
                    </template>
                    <x-ui.button type="submit" name="enabled" value="0" size="sm">Disable</x-ui.button>
                </form>
            </x-slot:bulk>

            @foreach ($modules as $module)
                <tr>
                    <td class="px-4 py-3">
                        // data-row-select is what the select-all counts; the label is per
                        // row, never a bare "Select" repeated 25 times.
                        <input
                            type="checkbox"
                            data-row-select
                            value="{{ $module->id }}"
                            x-model="selected"
                            aria-label="Select {{ $module->name }}"
                            class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600"
                        />
                    </td>
                    …
                </tr>
            @endforeach
        </x-ui.table>

    Rules the markup above is not free to break:
      · every row checkbox carries `data-row-select` (the select-all reads the DOM, so a row that
        does not carry it is silently unselectable) and a per-row `aria-label`;
      · the ids posted are whatever is ticked **on this page** — a selection does not survive
        pagination, and pretending otherwise would submit rows the user never saw;
      · the server re-checks the permission for every id. A bulk endpoint is exactly as dangerous
        as its loop; a ticked box is not authorization.

    `selection-name` / `selection-label` only affect wording and the documented input name; the
    bulk form is the view's own, so nothing here can post on its own.
--}}

@php
    $wrapper = $maxHeight
        ? 'overflow-auto'
        : 'overflow-x-auto';

    /*
     * `loading` is either a PHP boolean (server-side) or an Alpine expression. Bare `loading`
     * arrives as true, and the string forms of both booleans are treated as booleans so
     * loading="false" cannot accidentally become a JavaScript expression called "false".
     */
    $loadingExpression = null;
    $loadingNow = false;

    if (is_string($loading)) {
        $normalised = strtolower(trim($loading));

        if (in_array($normalised, ['', '0', 'false', 'null'], true)) {
            $loadingNow = false;
        } elseif (in_array($normalised, ['1', 'true'], true)) {
            $loadingNow = true;
        } else {
            $loadingExpression = trim($loading);
        }
    } else {
        $loadingNow = (bool) $loading;
    }

    // Skeleton width: the declared column count, else one <th> per header cell, else a sane five.
    $skeletonColumns = $columns !== null
        ? max(1, (int) $columns)
        : (isset($head) ? max(1, substr_count($head->toHtml(), '<th')) : 5);

    if ($selectable) {
        $skeletonColumns++; // the checkbox column this component adds itself
    }

    $skeletonRows = max(1, (int) $loadingRows);

    $bodyClasses = \Illuminate\Support\Arr::toCssClasses([
        '[&>tr>*]:border-b [&>tr>*]:border-slate-100 dark:[&>tr>*]:border-slate-800/80 [&>tr:last-child>*]:border-0',
        '[&>tr>*]:px-4 [&>tr>*]:py-2.5' => $dense,
        '[&>tr>*]:px-4 [&>tr>*]:py-3.5' => ! $dense,
        '[&>tr]:transition-colors [&>tr:hover]:bg-slate-50/80 dark:[&>tr:hover]:bg-slate-800/40' => $hover,
        'text-slate-700 dark:text-slate-300',
    ]);

    $checkboxClasses = 'h-4 w-4 cursor-pointer rounded border-slate-300 text-brand-600 transition focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800';
@endphp

<div
    @if ($selectable)
        {{--
            One Alpine scope per table. `keys()` reads the DOM rather than taking a row count
            prop, so select-all stays right when a view renders rows conditionally (a row the
            user may not act on simply carries no checkbox).
        --}}
        x-data="{
            selected: [],
            keys() {
                return Array.from(this.$root.querySelectorAll('tbody [data-row-select]'))
                    .map((box) => box.value);
            },
            get pickedCount() { return this.selected.length },
            get allSelected() {
                const total = this.keys().length;
                return total > 0 && this.selected.length >= total;
            },
            get someSelected() {
                return this.selected.length > 0 && ! this.allSelected;
            },
            toggleAll(checked) { this.selected = checked ? this.keys() : [] },
            clear() { this.selected = [] },
        }"
    @endif
    @if ($loadingExpression !== null)
        x-bind:aria-busy="({{ $loadingExpression }}) ? 'true' : 'false'"
    @elseif ($loadingNow)
        aria-busy="true"
    @endif
    {{ $attributes->class([
        'rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800' => ! $flush,
        'overflow-hidden' => ! $flush,
    ]) }}
>
    @if ($selectable)
        {{--
            Selection bar: shown only while something is ticked. It renders even without a `bulk`
            slot, because a tick with no visible count is a control that does nothing you can see.
        --}}
        <div
            x-show="pickedCount > 0"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            role="region"
            aria-label="Bulk actions"
            class="flex flex-wrap items-center gap-3 border-b border-brand-200 bg-brand-50 px-4 py-2.5 dark:border-brand-500/30 dark:bg-brand-500/10"
        >
            <p class="text-sm font-medium text-brand-800 dark:text-brand-200" aria-live="polite">
                <span class="tabular-nums" x-text="pickedCount"></span>
                <span x-text="pickedCount === 1 ? @js($selectionLabel) : @js(\Illuminate\Support\Str::plural($selectionLabel))"></span>
                selected
            </p>

            <button
                type="button"
                x-on:click="clear()"
                class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100 dark:text-brand-300 dark:hover:bg-brand-500/20"
            >
                Clear selection
            </button>

            @isset($bulk)
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    {{ $bulk }}
                </div>
            @endisset
        </div>
    @endif

    <div
        class="{{ $wrapper }}"
        @if ($maxHeight) style="max-height: {{ $maxHeight }}" @endif
    >
        <table class="min-w-full border-separate border-spacing-0 text-sm">
            {{-- Screen-reader-only, and needed only where a page carries more than one table: the
                 heading above already names it for anybody looking, but somebody moving between
                 tables hears "table" and "table" with none of the prose in between (A11Y-09). --}}
            @if (filled($caption))
                <caption class="sr-only">{{ $caption }}</caption>
            @endif

            @isset($head)
                <thead>
                    <tr class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur supports-[backdrop-filter]:bg-slate-50/80 dark:bg-slate-900/95 dark:supports-[backdrop-filter]:bg-slate-900/80 [&>*]:border-b [&>*]:border-slate-200 [&>*]:text-left [&>*]:text-xs [&>*]:font-semibold [&>*]:uppercase [&>*]:tracking-wider [&>*]:text-slate-500 dark:[&>*]:border-slate-800 dark:[&>*]:text-slate-400">
                        @if ($selectable)
                            <th scope="col" class="w-10 px-4 py-3">
                                {{--
                                    `indeterminate` is a DOM property, not an attribute, so it is
                                    set through x-effect rather than x-bind — otherwise "some of
                                    this page is ticked" would render as a plain empty box.
                                --}}
                                <input
                                    type="checkbox"
                                    class="{{ $checkboxClasses }}"
                                    aria-label="Select all {{ \Illuminate\Support\Str::plural($selectionLabel) }} on this page"
                                    x-bind:checked="allSelected"
                                    x-effect="$el.indeterminate = someSelected"
                                    x-on:change="toggleAll($event.target.checked)"
                                />
                            </th>
                        @endif

                        {{ $head }}
                    </tr>
                </thead>
            @endisset

            @if ($loadingNow)
                <tbody class="{{ $bodyClasses }}">
                    <x-ui.skeleton
                        variant="row"
                        :count="$skeletonRows"
                        :columns="$skeletonColumns"
                        :leading="$selectable ? 'check' : 'none'"
                    />
                </tbody>
            @else
                <tbody
                    class="{{ $bodyClasses }}"
                    @if ($loadingExpression !== null) x-show="! ({{ $loadingExpression }})" @endif
                >
                    {{ $slot }}
                </tbody>

                @if ($loadingExpression !== null)
                    <tbody class="{{ $bodyClasses }}" x-show="{{ $loadingExpression }}" x-cloak>
                        <x-ui.skeleton
                            variant="row"
                            :count="$skeletonRows"
                            :columns="$skeletonColumns"
                            :leading="$selectable ? 'check' : 'none'"
                        />
                    </tbody>
                @endif
            @endif

            @isset($foot)
                <tfoot class="[&>tr>*]:border-t [&>tr>*]:border-slate-200 [&>tr>*]:bg-slate-50/70 [&>tr>*]:px-4 [&>tr>*]:py-3 [&>tr>*]:text-xs [&>tr>*]:font-semibold [&>tr>*]:text-slate-600 dark:[&>tr>*]:border-slate-800 dark:[&>tr>*]:bg-slate-900/60 dark:[&>tr>*]:text-slate-300">
                    {{ $foot }}
                </tfoot>
            @endisset
        </table>
    </div>

    {{-- "Nothing here" and "not loaded yet" are different answers; never show both. --}}
    @if ($isEmpty && ! $loadingNow)
        <div
            class="border-t border-slate-200 dark:border-slate-800"
            @if ($loadingExpression !== null) x-show="! ({{ $loadingExpression }})" @endif
        >
            @isset($empty)
                {{ $empty }}
            @else
                <x-ui.empty-state />
            @endisset
        </div>
    @endif

    @isset($footer)
        <div class="border-t border-slate-200 bg-slate-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/60">
            {{ $footer }}
        </div>
    @endisset
</div>
