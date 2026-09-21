{{--
    ProfitThisMonthWidget body. Both bottom lines, because they answer different questions: "did the
    work pay" is before commission, "did the business keep anything" is after it.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="chart-bar" title="Profit unavailable"
                      message="One of the sources could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-rose-600 dark:text-rose-400' => $data['negative'],
                'text-slate-900 dark:text-white' => ! $data['negative'],
            ])>{{ money($data['net']) }}</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                after commission · {{ $data['range_label'] }}
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Income</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($data['income']) }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Expenses</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($data['expenses']) }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Commission paid</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($data['commission']) }}</dd>
            </div>
            <div class="flex justify-between gap-3 border-t border-slate-100 pt-1 dark:border-slate-800">
                <dt class="text-slate-500 dark:text-slate-400">Before commission</dt>
                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['before_commission']) }}</dd>
            </div>
        </dl>

        @if (($data['omitted'] ?? []) !== [])
            <p class="text-xs text-amber-600 dark:text-amber-400">
                Not included: {{ implode(', ', $data['omitted']) }}.
            </p>
        @endif
    </div>
@endif
