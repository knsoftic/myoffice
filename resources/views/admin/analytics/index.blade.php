@extends('layouts.admin')

@section('title', 'Analytics')

{{--
    The 98 analytics screen - admin.analytics.index (phase-19-23 7.8).

    **Each tile fetches itself.** Nine aggregates in one request would make the slowest chart decide
    how long everybody waits, and one that failed would take the page with it. Nine requests means
    nine tiles that fill in as they arrive - and a chart the viewer may not see answers 403 on its
    own endpoint, so the tile says so rather than rendering an empty box that reads as "no data".
--}}

@section('header')
    <x-ui.page-header title="Analytics"
                      subtitle="Every chart here reads the same service its module's own screen reads, so a chart and a page can never disagree."
                      icon="chart-bar">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('admin.reports.index')">Reports</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.select name="preset" label="Period">
                @foreach (\App\Support\DateRange::presets() as $value => $label)
                    <option value="{{ $value }}" @selected($range->preset() === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input type="date" name="from" label="From" :value="$range->start()->toDateString()" />
            <x-ui.form.input type="date" name="to" label="To" :value="$range->end()->toDateString()" />

            <div class="flex items-end">
                <x-ui.button type="submit" icon="funnel">Apply</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="grid gap-4 xl:grid-cols-2"
         x-data="{
             charts: {},
             async load(name) {
                 const params = new URLSearchParams(window.location.search);
                 const response = await fetch(`{{ route('admin.analytics.index') }}/chart/${name}?${params}`, {
                     headers: { 'Accept': 'application/json' },
                 });
                 this.charts[name] = await response.json();
             },
         }"
         x-init="@js(array_keys($charts)).forEach(name => load(name))">

        @foreach ($charts as $name => [$module, $permission])
            <x-ui.card x-cloak>
                <template x-if="! charts['{{ $name }}']">
                    <div class="space-y-3">
                        <x-ui.skeleton class="h-5 w-40" />
                        <x-ui.skeleton class="h-48 w-full" />
                    </div>
                </template>

                <template x-if="charts['{{ $name }}'] && ! charts['{{ $name }}'].available">
                    {{-- Says why, rather than showing an empty chart somebody reads as "nothing
                         happened" - which is a different and worse claim than "not for you". --}}
                    <div class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                        <p x-text="charts['{{ $name }}'].reason"></p>
                    </div>
                </template>

                <template x-if="charts['{{ $name }}'] && charts['{{ $name }}'].available">
                    <div>
                        <h3 class="font-semibold text-slate-800 dark:text-slate-100"
                            x-text="charts['{{ $name }}'].chart.title"></h3>

                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"
                           x-text="charts['{{ $name }}'].chart.description"></p>

                        <div class="mt-3" x-show="charts['{{ $name }}'].empty">
                            <p class="py-8 text-center text-sm text-slate-400 dark:text-slate-500">
                                Nothing fell inside this period.
                            </p>
                        </div>

                        <div class="mt-3" x-show="! charts['{{ $name }}'].empty">
                            <canvas :id="'analytics-' + '{{ $name }}'"
                                    x-init="$nextTick(() => window.renderAnalyticsChart?.($el, charts['{{ $name }}']))"
                                    height="240"></canvas>
                        </div>
                    </div>
                </template>
            </x-ui.card>
        @endforeach
    </div>
@endsection
