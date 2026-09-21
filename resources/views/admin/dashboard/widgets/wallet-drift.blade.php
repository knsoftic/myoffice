{{--
    WalletDriftWidget body. The one widget whose good state is a number, and it says when the last
    check ran — "nothing is drifting" means little if nothing has been checked since March.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="exclamation-triangle" title="Reconciliation unavailable"
                      message="The wallet tables could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-rose-600 dark:text-rose-400' => $data['count'] > 0,
                'text-slate-900 dark:text-white' => $data['count'] === 0,
            ])>{{ app_number($data['count']) }}</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ $data['count'] === 0 ? 'every wallet matches its ledger' : 'wallets out of step · ' . money($data['drift_total']) }}
            </p>
        </div>

        <div class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            @if ($data['failed'] > 0)
                <p class="flex items-center gap-1.5 text-rose-600 dark:text-rose-400">
                    <x-ui.icon name="x-circle" class="h-4 w-4" />
                    {{ app_number($data['failed']) }} disagree with their own ledger
                </p>
            @endif
            @if ($data['drifting'] > 0)
                <p class="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                    <x-ui.icon name="exclamation-triangle" class="h-4 w-4" />
                    {{ app_number($data['drifting']) }} cached figures out of date
                </p>
            @endif
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ $data['last_run'] === null
                    ? 'No reconciliation has ever run.'
                    : 'Last checked ' . app_datetime($data['last_run']) . '.' }}
            </p>
        </div>
    </div>
@endif
