{{--
    OutstandingReceivablesWidget body. The credits line is the point: a business holding an unapplied
    advance is owed less than its invoice balances say.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="document-text" title="Receivables unavailable"
                      message="The invoice register could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['outstanding']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                across {{ $data['invoices'] }} unpaid {{ \Illuminate\Support\Str::plural('invoice', (int) $data['invoices']) }}
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Unapplied credits</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($data['credits']) }}</dd>
            </div>
            <div class="flex justify-between gap-3 border-t border-slate-100 pt-1 dark:border-slate-800">
                <dt class="font-medium text-slate-900 dark:text-white">Net exposure</dt>
                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['net']) }}</dd>
            </div>
        </dl>
    </div>
@endif
