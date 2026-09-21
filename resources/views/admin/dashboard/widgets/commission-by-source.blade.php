{{--
    CommissionBySourceChartWidget body. Reversals are their own slice rather than netted into the
    source: a month with 500,000 earned and 80,000 refunded is a different month from one with
    420,000 earned.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="chart-pie" title="Commission unavailable"
                      message="The commission ledger could not be read." :compact="true" />
@else
    <div class="space-y-4">
        <x-ui.chart
            id="commission-by-source"
            type="doughnut"
            :labels="$data['labels']"
            :series="[['label' => 'Commission', 'data' => $data['values']]]"
            :height="220"
            table-label="Source"
            :decimals="2"
            :value-prefix="\App\Support\Money::symbol() . ' '"
            empty-icon="chart-pie"
            empty-title="No commission in this period"
            :empty-message="$widget->emptyMessage"
        />

        @if ($data['slices'] !== [])
            <dl class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                @foreach ($data['slices'] as $slice)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <x-ui.badge :color="$slice['color']" size="xs">{{ $slice['label'] }}</x-ui.badge>
                        </dt>
                        <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($slice['amount']) }}</dd>
                    </div>
                @endforeach
                <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-1.5 dark:border-slate-800">
                    <dt class="font-medium text-slate-900 dark:text-white">Net accrued</dt>
                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['net']) }}</dd>
                </div>
            </dl>
        @endif
    </div>
@endif
