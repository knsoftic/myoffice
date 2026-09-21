{{--
    ExpensesByCategoryChartWidget body. The same rows the expense report draws, so clicking through
    lands on a table that agrees with the chart to the paisa.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="chart-pie" title="Expenses unavailable"
                      message="The expense register could not be read." :compact="true" />
@else
    <div class="space-y-4">
        <x-ui.chart
            id="finance-expenses-by-category"
            type="doughnut"
            :labels="$data['labels']"
            :series="[['label' => 'Spent', 'data' => $data['values']]]"
            :height="220"
            table-label="Category"
            :decimals="2"
            :value-prefix="\App\Support\Money::symbol() . ' '"
            empty-icon="chart-pie"
            empty-title="No approved expenses in this period"
            :empty-message="$widget->emptyMessage ?? null"
        />

        @if (($data['slices'] ?? []) !== [])
            <dl class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                @foreach (array_slice($data['slices'], 0, 5) as $slice)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="truncate text-slate-600 dark:text-slate-300">{{ $slice['label'] }}</dt>
                        <dd class="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($slice['amount']) }}</dd>
                    </div>
                @endforeach
                <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-1.5 dark:border-slate-800">
                    <dt class="font-medium text-slate-900 dark:text-white">Total approved</dt>
                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['total']) }}</dd>
                </div>
            </dl>
        @endif
    </div>
@endif
