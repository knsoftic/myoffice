@extends('layouts.admin')

@section('title', 'Expense Sheet & Analytics')

@section('header')
    <x-ui.page-header title="Expense Sheet & Analytics"
                      subtitle="The expense register by day, week and month — the same filters as the table, drawn as a trend, a sheet and two splits."
                      icon="chart-bar">
        <x-slot:actions>
            @if ($canApprove)
                <x-ui.button variant="secondary" :href="route('admin.expenses.approvals')" icon="inbox-stack">
                    Approvals @if ($pendingCount > 0) ({{ $pendingCount }}) @endif
                </x-ui.button>
            @endif
            @can('expenses.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.expenses.export', ['format' => 'csv'] + request()->except('period'))">
                    Export
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

{{--
    Variable names here are prefixed "sheet": a page's variables are handed to the admin layout, and
    a generic name can collide with one the layout prints.
--}}
@php
    $sheetInitial = $requestedPeriod ?? $sheet->defaultPeriod;
    $sheetPrefix = $sheet->seesMoney ? \App\Support\Money::symbol().' ' : null;
    $sheetDecimals = $sheet->seesMoney ? 2 : 0;
    $sheetShow = static fn (string|int $amount): string => $sheet->seesMoney ? money((string) $amount) : app_number((int) $amount);
    $sheetStatusFiltered = filled(request('status'));
    $sheetScope = $sheetStatusFiltered
        ? 'Only the status you filtered by.'
        : 'Approved and awaiting approval. Rejected and voided claims are left out unless you filter by Status.';
@endphp

@section('content')
    @include('admin.expenses._view-switch')

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Approved" icon="check-circle" color="emerald"
                        :value="$sheetShow($sheet->statusTotal(\App\Enums\ExpenseStatus::Approved))"
                        :delta-label="$sheet->seesMoney ? app_number($sheet->statusCount(\App\Enums\ExpenseStatus::Approved)).' expenses, net of refunds' : 'expenses'" />
        <x-ui.stat-card label="Awaiting approval" icon="clock"
                        :color="$sheet->statusCount(\App\Enums\ExpenseStatus::Pending) > 0 ? 'amber' : 'slate'"
                        :value="$sheetShow($sheet->statusTotal(\App\Enums\ExpenseStatus::Pending))"
                        :delta-label="$sheet->seesMoney ? app_number($sheet->statusCount(\App\Enums\ExpenseStatus::Pending)).' claims, not in any report yet' : 'claims, not in any report yet'"
                        :href="$canApprove ? route('admin.expenses.approvals') : null" />
        <x-ui.stat-card label="Rejected" icon="x-circle" color="rose"
                        :value="$sheetShow($sheet->statusTotal(\App\Enums\ExpenseStatus::Rejected))"
                        :delta-label="$sheet->seesMoney ? app_number($sheet->statusCount(\App\Enums\ExpenseStatus::Rejected)).' claims' : 'claims'" />
        <x-ui.stat-card label="Expenses" icon="banknotes" color="slate"
                        :value="app_number($sheet->expenseCount)" :delta-label="$range->label()" />
    </div>

    @include('admin.expenses._filters')

    @unless ($fields->seesMoney)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            You can see which expenses exist and where they stand. The amounts need
            <code>expenses.view_financial</code>, which is a separate permission, so every figure below is a count of expenses.
        </div>
    @endunless

    <div x-data="expenseSheet(@js(['initial' => $sheetInitial, 'periods' => array_keys(\App\DataObjects\Finance\ExpenseSheet::PERIODS), 'fromQuery' => $requestedPeriod !== null]))"
         class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex flex-wrap rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="group" aria-label="Time breakdown">
                @foreach (\App\DataObjects\Finance\ExpenseSheet::PERIODS as $sheetKey => $sheetLabel)
                    <button type="button"
                            x-on:click="choose(@js($sheetKey))"
                            x-bind:aria-pressed="period === @js($sheetKey) ? 'true' : 'false'"
                            x-bind:class="period === @js($sheetKey) ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-900 dark:text-brand-300' : 'text-slate-500 dark:text-slate-400'"
                            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors duration-150 {{ $sheetKey === $sheetInitial ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-900 dark:text-brand-300' : 'text-slate-500 dark:text-slate-400' }}">
                        {{ $sheetLabel }}
                    </button>
                @endforeach
                <button type="button"
                        x-on:click="customRange()"
                        class="rounded-md px-3 py-1.5 text-xs font-medium text-slate-500 transition-colors duration-150 dark:text-slate-400">
                    Custom Date Range
                </button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $range->label() }} · {{ $sheetScope }}</p>
        </div>

        @foreach (\App\DataObjects\Finance\ExpenseSheet::PERIODS as $sheetKey => $sheetLabel)
            @php
                $sheetPeriod = $sheet->period($sheetKey);
                $sheetHeading = \App\DataObjects\Finance\ExpenseSheet::PERIOD_HEADINGS[$sheetKey];
            @endphp
            <div x-show="period === @js($sheetKey)"
                 data-sheet-panel="{{ $sheetKey }}"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 @if ($sheetKey !== $sheetInitial) x-cloak @endif
                 class="space-y-4">
                <x-ui.card :title="'Trend — '.$sheetLabel" icon="chart-bar"
                           :subtitle="$sheet->seesMoney ? 'Net of refunds, stacked by status.' : 'Number of expenses, stacked by status.'">
                    <x-ui.chart :id="'expense-trend-'.$sheetKey" type="bar" :stacked="true"
                                :labels="$sheetPeriod['labels']" :series="$sheetPeriod['series']" :height="280"
                                :table-label="$sheetHeading" :decimals="$sheetDecimals" :value-prefix="$sheetPrefix"
                                :max-x-ticks="$sheetKey === 'day' ? 10 : 12"
                                :summary="'Expenses '.strtolower($sheetLabel).', '.$range->label()"
                                empty-icon="chart-bar" empty-title="No expenses match these filters"
                                empty-message="Widen the From / To dates or clear a filter." />
                </x-ui.card>

                <x-ui.card :title="'Expense sheet — '.$sheetLabel" icon="table-cells" :padded="false"
                           :subtitle="($sheet->seesMoney ? 'Amounts in '.\App\Support\Money::symbol().', net of refunds' : 'Number of expenses').' · a row per '.strtolower($sheetHeading).', a column per category, status subtotals and a grand total.'">
                    @include('admin.expenses._sheet-pivot', ['key' => $sheetKey, 'rows' => $sheetPeriod['rows'], 'heading' => $sheetHeading])
                </x-ui.card>
            </div>
        @endforeach

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card title="By category" icon="chart-pie" :subtitle="$sheetScope">
                <div class="space-y-4">
                    <x-ui.chart id="expense-by-category" type="doughnut"
                                :labels="$sheet->categorySplit['labels']"
                                :series="[['label' => $sheet->seesMoney ? 'Spent' : 'Expenses', 'data' => $sheet->categorySplit['values']]]"
                                :height="240" table-label="Category" :decimals="$sheetDecimals" :value-prefix="$sheetPrefix"
                                summary="Expenses by category" empty-icon="chart-pie"
                                empty-title="No expenses match these filters" />

                    @if ($sheet->categorySplit['slices'] !== [])
                        <dl class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                            @foreach ($sheet->categorySplit['slices'] as $sheetSlice)
                                <div class="flex items-baseline justify-between gap-3">
                                    <dt class="truncate text-slate-600 dark:text-slate-300">{{ $sheetSlice['label'] }}</dt>
                                    <dd class="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">
                                        {{ $sheetShow($sheetSlice['value']) }}
                                        @if ($sheet->seesMoney)
                                            <span class="ml-1 text-xs font-normal text-slate-500 dark:text-slate-400">({{ app_number($sheetSlice['count']) }})</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                            <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-1.5 dark:border-slate-800">
                                <dt class="font-medium text-slate-900 dark:text-white">Total</dt>
                                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $sheetShow($sheet->categorySplit['total']) }}</dd>
                            </div>
                        </dl>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="By status" icon="check-badge" subtitle="Every status in the filtered set, rejected and voided included.">
                <div class="space-y-4">
                    <x-ui.chart id="expense-by-status" type="doughnut"
                                :labels="$sheet->statusSplit['labels']"
                                :series="[['label' => $sheet->seesMoney ? 'Amount' : 'Expenses', 'data' => $sheet->statusSplit['values'], 'colors' => $sheet->statusSplit['colors']]]"
                                :height="240" table-label="Status" :decimals="$sheetDecimals" :value-prefix="$sheetPrefix"
                                summary="Expenses by status" empty-icon="chart-pie"
                                empty-title="No expenses match these filters" />

                    @if ($sheet->statusSplit['slices'] !== [])
                        <dl class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                            @foreach ($sheet->statusSplit['slices'] as $sheetSlice)
                                <div class="flex items-baseline justify-between gap-3">
                                    <dt><x-ui.badge :color="$sheetSlice['color']" size="sm">{{ $sheetSlice['label'] }}</x-ui.badge></dt>
                                    <dd class="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">
                                        {{ $sheetShow($sheetSlice['value']) }}
                                        @if ($sheet->seesMoney)
                                            <span class="ml-1 text-xs font-normal text-slate-500 dark:text-slate-400">({{ app_number($sheetSlice['count']) }})</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                            <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-1.5 dark:border-slate-800">
                                <dt class="font-medium text-slate-900 dark:text-white">All statuses</dt>
                                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $sheetShow($sheet->statusSplit['total']) }}</dd>
                            </div>
                        </dl>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('expenseSheet', (config = {}) => ({
                period: config.initial,
                periods: config.periods || [],

                init() {
                    // A ?period= in the address wins; otherwise the breakdown this browser used last.
                    if (config.fromQuery) {
                        return;
                    }
                    try {
                        const saved = window.localStorage.getItem('expenseSheet.period');
                        if (this.periods.includes(saved) && saved !== this.period) {
                            this.period = saved;
                            this.redraw(saved);
                        }
                    } catch (error) {
                        // Storage blocked (private window): keep the server's choice.
                    }
                },

                // A chart drawn while its panel was hidden has no size. Once the panel is showing, draw
                // its charts again: they take the real width and play their entrance animation.
                redraw(period) {
                    this.$nextTick(() => setTimeout(() => {
                        const charts = window.uiCharts;
                        const panel = this.$root.querySelector('[data-sheet-panel="' + period + '"]');
                        if (! charts || ! panel) {
                            return;
                        }
                        panel.querySelectorAll('[data-chart]').forEach((root) => {
                            charts.destroy(root);
                            charts.create(root);
                        });
                    }, 0));
                },

                choose(period) {
                    if (! this.periods.includes(period) || period === this.period) {
                        return;
                    }
                    this.period = period;
                    this.redraw(period);
                    try {
                        window.localStorage.setItem('expenseSheet.period', period);
                    } catch (error) {
                        // Not remembered, still switched.
                    }
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('period', period);
                        window.history.replaceState(window.history.state, '', url);
                    } catch (error) {
                        // An old browser: the address simply stays as it was.
                    }
                },

                customRange() {
                    const from = document.querySelector('input[name="from"]');
                    if (from) {
                        from.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        from.focus({ preventScroll: true });
                    }
                },
            }));
        });
    </script>
@endpush
