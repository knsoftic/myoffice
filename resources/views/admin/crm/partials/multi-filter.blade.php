{{--
    A multi-select filter for x-ui.filter-bar (phase-05 §8.1: status and source are multi-select).

    @include('admin.crm.partials.multi-filter', [
        'name' => 'status',                          // posts status[]=…
        'label' => 'Status',
        'options' => $statusOptions,                 // value => label
        'selected' => (array) request('status', []),
    ])

    The filter bar submits on every `change`; ticking several boxes must not navigate after the first one, so the
    boxes stop their change event and the form is submitted once — by "Apply", or when the popover closes with
    something changed. Without JavaScript the boxes are plain inputs of the form and Enter still submits.
--}}

@php
    $filterName = (string) ($name ?? 'filter');
    $filterLabel = (string) ($label ?? \Illuminate\Support\Str::headline($filterName));
    $filterOptions = $options instanceof \Illuminate\Support\Collection ? $options->all() : (array) ($options ?? []);
    $filterSelected = array_map('strval', array_filter((array) ($selected ?? []), static fn ($v): bool => is_scalar($v) && $v !== ''));
    $filterId = 'multi-filter-'.\Illuminate\Support\Str::slug($filterName).'-'.uniqid();
@endphp

<div
    class="relative"
    x-data="{ open: false, dirty: false, count: {{ count($filterSelected) }} }"
    x-on:keydown.escape.stop="if (open) { open = false; if (dirty) $el.closest('form')?.requestSubmit(); }"
>
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-controls="{{ $filterId }}"
        class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-left text-xs text-slate-700 shadow-sm transition hover:bg-slate-50 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-200 dark:hover:bg-slate-900"
    >
        <span class="truncate">
            {{ $filterLabel }}
            <span x-show="count > 0" @if (count($filterSelected) === 0) x-cloak @endif class="ml-1 rounded-full bg-brand-100 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-brand-700 dark:bg-brand-500/20 dark:text-brand-300" x-text="count">{{ count($filterSelected) }}</span>
        </span>
        <x-ui.icon name="chevron-down" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
    </button>

    <div
        id="{{ $filterId }}"
        x-show="open"
        x-cloak
        x-transition.opacity
        x-on:click.outside="if (open) { open = false; if (dirty) $el.closest('form')?.requestSubmit(); }"
        class="absolute left-0 z-dropdown mt-2 w-60 max-w-[calc(100vw-2rem)] rounded-xl bg-white p-2 shadow-dropdown ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"
        role="group"
        aria-label="{{ $filterLabel }}"
    >
        <div class="max-h-64 space-y-0.5 overflow-y-auto">
            @forelse ($filterOptions as $optionValue => $optionLabel)
                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                    <input
                        type="checkbox"
                        name="{{ $filterName }}[]"
                        value="{{ $optionValue }}"
                        @checked(in_array((string) $optionValue, $filterSelected, true))
                        x-on:change.stop="dirty = true; count = $el.closest('[role=group]').querySelectorAll('input:checked').length"
                        class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800"
                    >
                    <span class="truncate">{{ $optionLabel }}</span>
                </label>
            @empty
                <p class="px-2 py-1.5 text-xs text-slate-500 dark:text-slate-400">No options.</p>
            @endforelse
        </div>

        <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
            <button
                type="button"
                x-on:click="$el.closest('[role=group]').querySelectorAll('input:checked').forEach((box) => { box.checked = false }); count = 0; dirty = true"
                class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
            >
                Clear
            </button>
            <x-ui.button type="submit" size="sm">Apply</x-ui.button>
        </div>
    </div>
</div>
