{{--
    RevenueThisMonthWidget body. Cash received, not invoices raised: billing a client is not revenue
    until they pay.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="banknotes" title="Revenue unavailable"
                      message="The income sources could not be read." :compact="true" />
@else
    @php
        $change = (string) $data['change'];
        $up = bccomp($change, '0.00', 2) === 1;
        $flat = bccomp($change, '0.00', 2) === 0;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['current']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                received · {{ $data['range_label'] }}
            </p>
        </div>

        <div class="border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            @if ($flat)
                <p class="text-slate-500 dark:text-slate-400">Unchanged from {{ $data['previous_label'] }}.</p>
            @else
                <p class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                    <x-ui.icon :name="$up ? 'arrow-trending-up' : 'arrow-trending-down'"
                               class="h-4 w-4 {{ $up ? 'text-emerald-500' : 'text-rose-500' }}" />
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ money(ltrim($change, '-')) }}
                    </span>
                    {{ $up ? 'more than' : 'less than' }} {{ $data['previous_label'] }}
                </p>
            @endif
        </div>
    </div>
@endif
