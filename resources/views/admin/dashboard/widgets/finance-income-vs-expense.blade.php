{{--
    IncomeVsExpenseChartWidget body. Twelve months regardless of the dashboard range: the point is the
    shape of the year, and a trend that reset to "this week" would show a single bar.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="presentation-chart-line" title="Trend unavailable"
                      message="One of the sources could not be read." :compact="true" />
@else
    <x-ui.chart
        id="finance-income-vs-expense"
        type="bar"
        :labels="$data['labels']"
        :series="[
            ['label' => 'Received', 'data' => $data['income']],
            ['label' => 'Spent', 'data' => $data['expenses']],
        ]"
        :height="260"
        table-label="Month"
        :decimals="2"
        :max-x-ticks="12"
        :value-prefix="\App\Support\Money::symbol() . ' '"
        empty-icon="presentation-chart-line"
        empty-title="No money has moved"
        :empty-message="$widget->emptyMessage ?? null"
    />
@endif
