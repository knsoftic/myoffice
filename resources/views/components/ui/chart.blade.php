@props([
    'id' => null,
    'type' => 'line',
    'labels' => [],
    'series' => [],
    'height' => 260,
    'stacked' => false,
    'legend' => null,
    'yLabel' => null,
    'valuePrefix' => null,
    'valueSuffix' => null,
    'decimals' => 1,
    'maxXTicks' => 8,
    'summary' => null,
    'tableLabel' => 'Category',
    'emptyIcon' => 'chart-bar',
    'emptyTitle' => 'Nothing to chart yet',
    'emptyMessage' => null,
])

{{--
    x-ui.chart — the project's only chart. Chart.js via npm, drawn by resources/js/charts.js.

        <x-ui.chart
            id="login-trend"
            type="line"
            :labels="$data['labels']"
            :series="$data['series']"
            :height="260"
            summary="Successful and failed sign-ins per day"
            table-label="Day"
        />

    `series` is a list of ['label' => …, 'data' => [...], 'color' => 'emerald', 'fill' => false].
    `color` takes the same Tailwind token every enum's color() returns, so a chart matches the
    badges beside it; `brand` follows the branding.brand_color setting at runtime.

    Types: line (default), bar, doughnut. Pass :stacked="true" for a stacked bar or area.

    ── Why there is always a table ─────────────────────────────────────────────────────────────
    A canvas is invisible to a screen reader and blank with JavaScript off. So every chart ships
    its own data as a real <table>, screen-reader-only while the canvas is drawn, and promoted to
    the visible element by the <noscript> rule below when there is no JavaScript to draw with.
    Nothing is duplicated by hand: the table and the canvas read the same `series`.
--}}

@php
    $chartId = $id ?? 'chart-'.\Illuminate\Support\Str::random(8);

    // Only series that actually carry points. An all-zero series is real data (a flat line is an
    // answer); an empty one is not, and the card should say so instead of drawing an empty axis.
    $plotted = collect($series)
        ->filter(fn ($dataset): bool => is_array($dataset) && ! empty($dataset['data'] ?? []))
        ->map(fn (array $dataset): array => [
            'label' => (string) ($dataset['label'] ?? ''),
            'data' => array_values((array) $dataset['data']),
            'color' => $dataset['color'] ?? null,
            'colors' => $dataset['colors'] ?? null,
            'fill' => $dataset['fill'] ?? null,
        ])
        ->values();

    $rowLabels = array_values((array) $labels);

    $config = [
        'type' => $type,
        'labels' => $rowLabels,
        'series' => $plotted->all(),
        'stacked' => (bool) $stacked,
        'legend' => $legend,
        'yLabel' => $yLabel,
        'valuePrefix' => $valuePrefix,
        'valueSuffix' => $valueSuffix,
        'decimals' => (int) $decimals,
        'maxXTicks' => (int) $maxXTicks,
    ];

    $height = max(120, min(640, (int) $height));

    // A one-line summary is what a screen reader hears instead of the picture.
    $described = $summary ?? trim(collect($plotted)->pluck('label')->filter()->implode(' and ').' over '.count($rowLabels).' points');
@endphp

@once('ui-chart-noscript')
    @push('styles')
        {{-- No JavaScript: hide the canvas and promote the sr-only table to ordinary content. --}}
        <noscript>
            <style>
                [data-chart-canvas] { display: none !important; }
                [data-chart-fallback] {
                    position: static !important;
                    width: auto !important;
                    height: auto !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow: visible !important;
                    clip: auto !important;
                    clip-path: none !important;
                    white-space: normal !important;
                }
            </style>
        </noscript>
    @endpush
@endonce

@if ($plotted->isEmpty() || $rowLabels === [])
    <x-ui.empty-state
        :icon="$emptyIcon"
        :title="$emptyTitle"
        :message="$emptyMessage"
        :compact="true"
    />
@else
    <div
        {{ $attributes->class('relative') }}
        id="{{ $chartId }}"
        data-chart
        data-chart-type="{{ $type }}"
    >
        {{-- The runtime reads this; a non-executable script type survives an innerHTML swap. --}}
        <script type="application/json" data-chart-config>@json($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)</script>

        <div data-chart-canvas style="height: {{ $height }}px">
            <canvas
                role="img"
                aria-label="{{ $described }}"
                height="{{ $height }}"
            ></canvas>
        </div>

        {{-- Always present, always accurate: the same numbers as the canvas. --}}
        <div data-chart-fallback class="sr-only">
            <div class="overflow-x-auto">
                <table class="min-w-full border-separate border-spacing-0 text-sm">
                    <caption class="px-1 pb-2 text-left text-xs text-slate-500 dark:text-slate-400">
                        {{ $described }}
                    </caption>

                    <thead>
                        <tr class="[&>*]:border-b [&>*]:border-slate-200 [&>*]:px-3 [&>*]:py-2 [&>*]:text-left [&>*]:text-xs [&>*]:font-semibold [&>*]:uppercase [&>*]:tracking-wider [&>*]:text-slate-500 dark:[&>*]:border-slate-800 dark:[&>*]:text-slate-400">
                            <th scope="col">{{ $tableLabel }}</th>
                            @foreach ($plotted as $dataset)
                                <th scope="col" class="text-right">{{ $dataset['label'] ?: 'Value' }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="[&>tr>*]:border-b [&>tr>*]:border-slate-100 [&>tr>*]:px-3 [&>tr>*]:py-2 dark:[&>tr>*]:border-slate-800/80 [&>tr:last-child>*]:border-0">
                        @foreach ($rowLabels as $index => $label)
                            <tr>
                                <th scope="row" class="font-medium text-slate-700 dark:text-slate-300">{{ $label }}</th>
                                @foreach ($plotted as $dataset)
                                    <td class="text-right tabular-nums text-slate-700 dark:text-slate-300">
                                        {{ app_number($dataset['data'][$index] ?? 0) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@once('ui-chart-runtime')
    @push('scripts')
        @vite('resources/js/charts.js')
    @endpush
@endonce
