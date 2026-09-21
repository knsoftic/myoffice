{{--
    ProjectPaymentsThisMonthWidget body. Received money, net of refunds — the same basis every
    commission in this system follows.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="banknotes" title="Payments unavailable"
                      message="The project payments table could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['net']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                received · {{ $data['range_label'] }}
            </p>
        </div>

        @if ($data['count'] > 0)
            <dl class="space-y-1.5 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Payments</dt>
                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number($data['count']) }}</dd>
                </div>
                @if (bccomp((string) $data['refunded'], '0.00', 2) === 1)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Refunded</dt>
                        <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ money($data['refunded']) }}</dd>
                    </div>
                @endif
            </dl>
        @else
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $widget->emptyMessage }}</p>
        @endif
    </div>
@endif
