{{--
    The global date-range selector.

    A plain GET form, so the range is in the URL: bookmarkable, shareable, back-button correct, and
    fully functional with JavaScript off (the Apply button is only hidden when Alpine is running).
    Every widget on the page is handed the `DateRange` this form produces — the controller builds it
    once and passes the same instance to all of them.

    Expects: $range (DateRange), $rangePresets (value => label), $dashboardUrl.
--}}

@php
    $presets = $rangePresets ?? \App\Support\DateRange::presets();
    $isCustom = $range->isCustom();
@endphp

<form
    method="GET"
    action="{{ $dashboardUrl }}"
    x-data="{ preset: @js($range->preset()), custom: @js($isCustom) }"
    x-ref="rangeForm"
    class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-2 ring-1 ring-slate-200/70 shadow-sm dark:bg-slate-900 dark:ring-slate-800"
>
    <label for="dashboard-range" class="sr-only">Date range</label>

    <div class="relative">
        <select
            id="dashboard-range"
            name="range"
            x-model="preset"
            x-on:change="custom = (preset === 'custom'); if (! custom) { $refs.rangeForm.submit() }"
            class="h-9 rounded-lg border-slate-300 bg-white py-0 pl-9 pr-8 text-sm font-medium text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
        >
            @foreach ($presets as $value => $label)
                <option value="{{ $value }}" @selected($range->preset() === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <x-ui.icon
            name="calendar-days"
            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 dark:text-slate-500"
        />
    </div>

    {{--
        The two date inputs only matter for the custom preset. Hidden with an inline style rather
        than x-cloak on purpose: x-cloak stays applied when JavaScript never runs, which would make
        a bookmarked custom range un-editable without it.

        Format note: the four toDateString() calls below are deliberate and are not display formatting.
        An <input type="date"> only accepts an ISO 'Y-m-d' value (the browser localises what it shows),
        and DateRange::make() parses the same wire format back. The dates are already in the range's
        own timezone (DateRange builds start/end there), so no UTC timestamp is formatted raw here.
    --}}
    <div
        class="flex flex-wrap items-center gap-2"
        x-show="custom"
        @unless ($isCustom) style="display: none" @endunless
    >
        <label for="dashboard-range-from" class="sr-only">From</label>
        <input
            type="date"
            id="dashboard-range-from"
            name="from"
            value="{{ $range->start()->toDateString() }}"
            max="{{ now($range->timezone())->toDateString() }}"
            class="h-9 rounded-lg border-slate-300 bg-white py-0 text-sm tabular-nums text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
        />

        <span class="text-xs text-slate-400 dark:text-slate-500">to</span>

        <label for="dashboard-range-to" class="sr-only">To</label>
        <input
            type="date"
            id="dashboard-range-to"
            name="to"
            value="{{ $range->end()->toDateString() }}"
            max="{{ now($range->timezone())->toDateString() }}"
            class="h-9 rounded-lg border-slate-300 bg-white py-0 text-sm tabular-nums text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
        />

        <x-ui.button type="submit" size="sm" icon="check">Apply</x-ui.button>
    </div>

    {{-- JavaScript off: the select cannot auto-submit, so keep a visible Apply. --}}
    <noscript>
        <x-ui.button type="submit" size="sm" variant="secondary" icon="check">Apply</x-ui.button>
    </noscript>

    <span class="hidden items-center gap-1.5 pl-1 pr-2 text-xs text-slate-500 sm:inline-flex dark:text-slate-400">
        <x-ui.icon name="information-circle" class="h-3.5 w-3.5" />
        {{ $range->days() === 1 ? '1 day' : app_number($range->days()).' days' }}
        <span class="text-slate-300 dark:text-slate-700">·</span>
        vs {{ $range->previous()->label() }}
    </span>
</form>
