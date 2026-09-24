@extends('layouts.admin')

@section('title', $definition->title())

{{--
    One report — admin.reports.show (phase-19-23 §7.8, §99).

    Two things on this page exist because of INV-23-2, and both are easy to mistake for decoration.

    The "some columns are not shown" notice names the columns this viewer was refused. A narrower
    table that said nothing would read as the whole report, and its totals would be read as complete
    totals — which is exactly the failure the invariant is about. Naming them tells somebody the
    table is narrower without telling them what was in it.

    The "filters were removed" notice does the same for filters. A report that came back narrower
    than asked should say so, rather than leaving somebody to wonder why a number looks small.
--}}

@section('header')
    <x-ui.page-header :title="$definition->title()"
                      :subtitle="$definition->description()"
                      :icon="$definition->icon()">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('admin.reports.index')">All reports</x-ui.button>

            @can('reports.print')
                <x-ui.button variant="secondary" icon="printer"
                             :href="route('admin.reports.print', [$definition->key(), ...request()->query()])"
                             target="_blank">
                    Print
                </x-ui.button>
            @endcan

            @can('reports.export')
                <x-ui.dropdown align="right">
                    <x-slot:trigger>
                        <x-ui.button variant="secondary" icon="arrow-down-tray">Export</x-ui.button>
                    </x-slot:trigger>

                    @foreach ($schema->formats as $format)
                        <x-ui.dropdown-item :href="route('admin.reports.export', [$definition->key(), $format->value, ...request()->query()])"
                                            :icon="$format->icon()">
                            {{ $format->label() }}
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @php
        $meta = $result->meta;
        $omitted = $meta['omitted_columns'] ?? [];
        $stripped = $meta['stripped_filters'] ?? [];
        $columns = $schema->columns;
    @endphp

    {{-- The filter bar, built from the schema so it can only ever offer what this viewer may use. --}}
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.select name="preset" label="Period">
                @foreach (\App\Support\DateRange::presets() as $value => $label)
                    <option value="{{ $value }}" @selected(($meta['preset'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
                <option value="custom" @selected(($meta['preset'] ?? '') === 'custom')>Custom</option>
            </x-ui.form.select>

            <x-ui.form.input type="date" name="from" label="From" :value="$meta['from'] ?? null" />
            <x-ui.form.input type="date" name="to" label="To" :value="$meta['to'] ?? null" />

            @if ($schema->dateFilter?->hasChoice())
                {{-- Changing this changes the figures, which is why it is a control and not a
                     footnote: "fees this month" means something different for each column. --}}
                <x-ui.form.select name="date_column" label="Measured on">
                    @foreach ($schema->dateFilter->choices() as $column => $label)
                        <option value="{{ $column }}" @selected(($meta['date_column'] ?? '') === $column)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>
            @endif

            @foreach ($schema->filters as $filter)
                @include('admin.reports.partials.filter', ['filter' => $filter, 'current' => $meta['filters'] ?? []])
            @endforeach

            <div class="flex items-end gap-2 sm:col-span-2 xl:col-span-4">
                <x-ui.button type="submit" icon="funnel">Apply</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.reports.show', $definition->key())">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- What this report actually covered. Printed on screen as well as in the file, so the two
         cannot disagree about what was asked. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <x-ui.badge color="slate">{{ $meta['range_label'] ?? '' }}</x-ui.badge>

        @if (! empty($meta['date_label']))
            <span>measured on <strong class="font-medium text-slate-700 dark:text-slate-200">{{ mb_strtolower($meta['date_label']) }}</strong></span>
        @endif

        <span>· {{ app_number($result->rowCount()) }} {{ \Illuminate\Support\Str::plural('row', $result->rowCount()) }}</span>

        @if (! empty($meta['cached']))
            <span class="text-xs">· from cache</span>
        @endif
    </div>

    @if ($omitted !== [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <strong class="font-semibold">Some columns are not shown:</strong>
            {{ implode(', ', $omitted) }}.
            Totals below cover only the columns you can see.
        </div>
    @endif

    @if ($stripped !== [])
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
            <strong class="font-semibold">Some filters were not applied:</strong>
            {{ implode(', ', $stripped) }} — you do not have permission to narrow by those.
        </div>
    @endif

    @if (($meta['available'] ?? true) === false)
        <x-ui.empty-state icon="clock"
                          title="This report is not available yet"
                          :description="$meta['reason'] ?? ''" />
    @elseif ($schema->chart && $result->rows !== [])
        @php
            // `x-ui.chart` wants labels and series, so the definition is projected onto the rows
            // here rather than in the component: the component is shared with every other chart in
            // the system and should not learn what a ReportResult is.
            $chart = $schema->chart->toArray();
            $chartLabels = array_column($result->rows, $chart['label_key']);
            $chartSeries = [];

            foreach ($chart['series'] as $key => $label) {
                $chartSeries[] = [
                    'label' => $label,
                    'data' => array_map(
                        static fn (array $row): float => (float) ($row[$key] ?? 0),
                        $result->rows,
                    ),
                ];
            }
        @endphp

        <x-ui.card class="mb-4">
            <x-ui.chart :type="$chart['primitive']"
                        :labels="$chartLabels"
                        :series="$chartSeries"
                        :stacked="$chart['stacked']"
                        :summary="$chart['description']"
                        :table-label="$chart['title']" />
        </x-ui.card>
    @endif

    <x-ui.card>
        @if ($result->rows === [] && ($meta['available'] ?? true) !== false)
            <x-ui.empty-state icon="magnifying-glass"
                              title="Nothing matched"
                              description="No rows fall inside this period with these filters. Try a wider range." />
        @elseif ($result->rows !== [])
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col" class="px-3 py-2 text-{{ $column->alignment() }} text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $column->label }}
                                @if ($column->help)
                                    <span class="ml-1 text-slate-400" title="{{ $column->help }}">?</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </x-slot:head>

                @foreach ($result->rows as $row)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        @foreach ($columns as $column)
                            <td class="px-3 py-2 text-{{ $column->alignment() }} text-sm text-slate-700 dark:text-slate-200">
                                @include('admin.reports.partials.cell', ['column' => $column, 'value' => $row[$column->key] ?? null])
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                @if ($result->totals !== [])
                    <x-slot:foot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-700 dark:bg-slate-800/50">
                            @foreach ($columns as $index => $column)
                                <td class="px-3 py-2 text-{{ $column->alignment() }} text-sm text-slate-800 dark:text-slate-100">
                                    @if ($index === 0)
                                        Total
                                    @elseif (array_key_exists($column->key, $result->totals))
                                        @include('admin.reports.partials.cell', ['column' => $column, 'value' => $result->totals[$column->key]])
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </x-slot:foot>
                @endif
            </x-ui.table>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-slate-400 dark:text-slate-500">
        Generated {{ $meta['generated_at'] ?? '' }} · {{ auth()->user()?->name }}
    </p>
@endsection
