{{--
    PayoutQueueWidget body. Two queues, because they need two different people: approving is a
    decision, paying is a trip to the bank.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="queue-list" title="Queue unavailable"
                      message="The payouts table could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ app_number($data['count']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                waiting · {{ money($data['total']) }}
            </p>
        </div>

        @if ($data['count'] > 0)
            <div class="space-y-2 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                <a href="{{ $data['approve_href'] }}"
                   class="flex items-baseline justify-between gap-3 rounded px-1 py-0.5 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <span class="text-slate-500 dark:text-slate-400">To approve</span>
                    <span class="text-right">
                        <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number($data['to_approve']['count']) }}</span>
                        <span class="block text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ money($data['to_approve']['total']) }}</span>
                    </span>
                </a>
                <a href="{{ $data['pay_href'] }}"
                   class="flex items-baseline justify-between gap-3 rounded px-1 py-0.5 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <span class="text-slate-500 dark:text-slate-400">To pay</span>
                    <span class="text-right">
                        <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number($data['to_pay']['count']) }}</span>
                        <span class="block text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ money($data['to_pay']['total']) }}</span>
                    </span>
                </a>
            </div>
        @else
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $widget->emptyMessage }}</p>
        @endif
    </div>
@endif
