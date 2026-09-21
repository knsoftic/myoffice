{{--
    WalletLiabilityWidget body. Derived from the ledger in one query, never summed from the caches —
    a liability figure is the last number anybody should take on trust.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="scale" title="Liability unavailable"
                      message="The commission ledger could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['owed']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">earned and not yet paid out</p>
        </div>

        <dl class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Payable now</dt>
                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['payable']) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Awaiting approval</dt>
                <dd class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($data['pending']) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Reserved by payouts</dt>
                <dd class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($data['reserved']) }}</dd>
            </div>
        </dl>
    </div>
@endif
