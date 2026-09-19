{{--
    LeadConversionRateWidget body (phase-05 §8.11) — key `lead_conversion_rate`, span 4, permission leads.view_reports,
    module leads. won / (won + lost) and converted / total for the range; a rate with nothing to divide by is a dash, not 0%.

    $data (the widget's data()):
      available        bool
      won, lost        int
      win_rate         ?string  Format::percentage(won / (won + lost)); null when won + lost = 0
      converted        int
      total            int      leads created in the range
      conversion_rate  ?string  Format::percentage(converted / total); null when total = 0
      range_label      string
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="trophy" title="Rates unavailable" message="The lead figures could not be read." :compact="true" />
@elseif ((int) ($data['total'] ?? 0) === 0 && (int) ($data['won'] ?? 0) + (int) ($data['lost'] ?? 0) === 0)
    <x-ui.empty-state icon="trophy" title="Nothing closed yet" :message="$widget->emptyMessage ?? 'Win and conversion rates appear once leads are won or lost.'" :compact="true" />
@else
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Win rate</p>
            <p class="mt-1 text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ $data['win_rate'] ?? '—' }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                <span class="tabular-nums text-emerald-700 dark:text-emerald-400">{{ app_number((int) ($data['won'] ?? 0)) }} won</span> ·
                <span class="tabular-nums text-rose-700 dark:text-rose-400">{{ app_number((int) ($data['lost'] ?? 0)) }} lost</span>
            </p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Converted</p>
            <p class="mt-1 text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ $data['conversion_rate'] ?? '—' }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                <span class="tabular-nums">{{ app_number((int) ($data['converted'] ?? 0)) }}</span> of <span class="tabular-nums">{{ app_number((int) ($data['total'] ?? 0)) }}</span> leads
            </p>
        </div>
        <p class="col-span-2 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ $data['range_label'] ?? '' }}</p>
    </div>
@endif
