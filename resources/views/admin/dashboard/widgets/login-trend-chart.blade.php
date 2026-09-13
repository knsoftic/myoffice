{{--
    LoginTrendChartWidget body — the dashboard's one chart.

    Drawn by <x-ui.chart>, which also ships the same numbers as a table for screen readers and for a
    browser with JavaScript off. Nothing here duplicates the chart's data: the table inside the
    component reads the very arrays handed to the canvas.
--}}

@if (! ($data['available'] ?? false) || empty($data['labels']))
    <x-ui.empty-state
        icon="chart-bar"
        title="No sign-ins to chart"
        :message="$widget->emptyMessage"
        :compact="true"
    />
@else
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                <span class="inline-flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    Successful
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ app_number($data['totals']['success']) }}
                    </span>
                </span>

                <span class="inline-flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500" aria-hidden="true"></span>
                    Failed or blocked
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ app_number($data['totals']['failed']) }}
                    </span>
                </span>
            </div>

            <span class="text-xs text-slate-400 dark:text-slate-500">
                {{ app_number($data['days']) }} days
                @if (filled($data['note'] ?? null))
                    <span title="The window never extends past today, is never shorter than 14 days and never longer than 90, so the line is always readable and never plots the future.">
                        ({{ $data['note'] }})
                    </span>
                @endif
            </span>
        </div>

        <x-ui.chart
            id="widget-chart-{{ $widget->key }}"
            type="line"
            :labels="$data['labels']"
            :series="$data['series']"
            :height="220"
            :legend="false"
            :max-x-ticks="7"
            table-label="Day"
            :summary="'Successful and failed sign-ins per day over '.$data['window_label']"
        />

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            <span>
                Average
                <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-200">
                    {{ app_number($data['average'], 1) }}
                </span>
                sign-ins a day
            </span>

            @if ($data['peak'] !== null && $data['peak']['successful'] > 0)
                <span class="text-slate-300 dark:text-slate-700">·</span>
                <span>
                    Busiest
                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $data['peak']['label'] }}</span>
                    (<span class="tabular-nums">{{ app_number($data['peak']['successful']) }}</span>)
                </span>
            @endif
        </div>
    </div>
@endif
