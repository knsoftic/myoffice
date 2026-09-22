{{--
    FeeCollectedTodayWidget body (phase-18 §8.10, requirement §88).

    Two figures, deliberately: "we took 40,000 today" means very little without "and 310,000 this
    month" beside it. Both are `net_received_amount`, the generated column — a refund taken today
    cannot be forgotten the way it could if this summed `amount` and subtracted refunds separately.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="banknotes" title="Fees unavailable"
                      message="The receipts could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['today']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                today · {{ app_number($data['today_count']) }} {{ (int) $data['today_count'] === 1 ? 'receipt' : 'receipts' }}
            </p>
        </div>

        <div class="border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            <p class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['range']) }}</span>
                over {{ $data['range_label'] }}
            </p>
        </div>
    </div>
@endif
