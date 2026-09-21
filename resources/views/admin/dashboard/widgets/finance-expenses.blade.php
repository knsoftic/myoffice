{{--
    ExpensesThisMonthWidget body. Approved only — a pending claim is not yet a cost the business has
    agreed to, and including it would make this figure move every time somebody typed a number.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="receipt-percent" title="Expenses unavailable"
                      message="The expense register could not be read." :compact="true" />
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
                approved · {{ $data['range_label'] }}
            </p>
        </div>

        <div class="space-y-1 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            @if ($flat)
                <p class="text-slate-500 dark:text-slate-400">Unchanged from {{ $data['previous_label'] }}.</p>
            @else
                <p class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                    <x-ui.icon :name="$up ? 'arrow-trending-up' : 'arrow-trending-down'"
                               class="h-4 w-4 {{ $up ? 'text-rose-500' : 'text-emerald-500' }}" />
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ money(ltrim($change, '-')) }}
                    </span>
                    {{ $up ? 'more than' : 'less than' }} {{ $data['previous_label'] }}
                </p>
            @endif

            @if (($data['pending_count'] ?? 0) > 0)
                <p class="text-xs text-amber-600 dark:text-amber-400">
                    {{ $data['pending_count'] }} more, worth {{ money($data['pending_amount']) }}, waiting for approval.
                </p>
            @endif
        </div>
    </div>
@endif
