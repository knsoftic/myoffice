{{--
    FeesCollectedTodayWidget body.

    Net of refunds, because the question this card answers at six o'clock is whether the drawer
    agrees with the system — and the gross figure was never in the drawer.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="banknotes" title="Collection unavailable"
                      message="Today's receipts could not be read." :compact="true" />
@elseif (($data['receipts'] ?? 0) === 0)
    <x-ui.empty-state icon="banknotes" title="Nothing taken yet"
                      message="No fee has been received today." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['net']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                net today · {{ $data['receipts'] }} {{ Str::plural('receipt', $data['receipts']) }}
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            @if (($data['mine_net'] ?? null) !== null)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Taken by you</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">
                        {{ money($data['mine_net']) }}
                        <span class="text-slate-400 dark:text-slate-500">({{ $data['mine_receipts'] }})</span>
                    </dd>
                </div>
            @endif

            @unless (\App\Support\Money::isZero($data['refunded'] ?? '0'))
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Refunded today</dt>
                    <dd class="tabular-nums font-semibold text-amber-600 dark:text-amber-400">
                        {{ money($data['refunded']) }}
                    </dd>
                </div>
            @endunless
        </dl>

        <p class="text-xs text-slate-400 dark:text-slate-500">
            Net of refunds. Voided and bounced receipts are excluded.
        </p>
    </div>
@endif
