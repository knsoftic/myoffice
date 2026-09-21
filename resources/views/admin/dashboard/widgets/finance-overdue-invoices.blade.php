{{--
    OverdueInvoicesWidget body. The oldest one is named, because "four overdue" is a statistic and a
    number with a client on it is something somebody can act on this morning.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="exclamation-triangle" title="Invoices unavailable"
                      message="The invoice register could not be read." :compact="true" />
@elseif (($data['count'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing is overdue"
                      message="Every issued invoice is inside its terms." :compact="true" />
@else
    @php $worst = $data['worst'] ?? null; @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-rose-600 tabular-nums dark:text-rose-400">
                {{ $data['count'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                worth {{ money($data['amount']) }}
            </p>
        </div>

        @if ($worst)
            <div class="border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Oldest</p>
                <p class="mt-0.5 text-slate-700 dark:text-slate-200">
                    @if ($data['worst_link'] ?? null)
                        <a href="{{ $data['worst_link'] }}" class="font-mono text-xs font-semibold hover:underline">
                            {{ $worst->invoice_number }}
                        </a>
                    @else
                        <span class="font-mono text-xs font-semibold">{{ $worst->invoice_number }}</span>
                    @endif
                    — {{ $worst->company_name ?: $worst->client_name }}
                </p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    due {{ app_date($worst->due_date) }} · {{ money($worst->balance_amount) }} outstanding
                </p>
            </div>
        @endif
    </div>
@endif
