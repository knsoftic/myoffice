{{--
    NewLeadsTrendWidget body (phase-05 §8.11) — key `new_leads_trend`, span 8, permission leads.view_reports, module leads.
    A 14-day line through x-ui.chart (which also ships the numbers as a table for screen readers and no-JS).

    $data (the widget's data()):
      available     bool
      labels        list<string>   one per day, already formatted with app_date()
      series        list<array{label: string, data: list<int>, color: string}>   e.g. "New leads" (brand), "Won" (emerald)
      total         int            new leads across the window
      window_label  string
--}}

@if (! ($data['available'] ?? false) || empty($data['labels'] ?? []))
    <x-ui.empty-state icon="chart-bar" title="No leads to chart" :message="$widget->emptyMessage ?? 'New leads per day appear here.'" :compact="true" />
@else
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs">
            <span class="text-slate-600 dark:text-slate-300">
                <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($data['total'] ?? 0)) }}</span> new leads
            </span>
            <span class="text-slate-400 dark:text-slate-500">{{ $data['window_label'] ?? '' }}</span>
        </div>

        <x-ui.chart
            id="widget-chart-{{ $widget->key ?? 'new-leads-trend' }}"
            type="line"
            :labels="$data['labels']"
            :series="$data['series'] ?? []"
            :height="220"
            :legend="count($data['series'] ?? []) > 1"
            :max-x-ticks="7"
            :decimals="0"
            table-label="Day"
            :summary="'New leads per day over '.($data['window_label'] ?? 'the last 14 days')"
        />
    </div>
@endif
