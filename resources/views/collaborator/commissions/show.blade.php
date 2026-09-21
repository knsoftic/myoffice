@extends('layouts.panel')

@section('title', 'Commission ' . $entry->reference)

@section('header')
    <x-ui.page-header :title="money($entry->amount)"
                      :subtitle="$entry->purpose->label() . ' · ' . app_date($entry->transaction_date)"
                      icon="calculator"
                      :badge="$entry->status->label()"
                      :badge-color="$entry->status->color()"
                      :back="route('collaborator.commissions.index')" />
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="How this was worked out"
                       subtitle="The rule that was in force on the day the money was received — not the rule in force today.">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Money received</dt>
                        <dd class="text-right tabular-nums text-slate-900 dark:text-white">{{ money($entry->gross_amount) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Commission worked out on</dt>
                        <dd class="text-right tabular-nums text-slate-900 dark:text-white">
                            {{ money($entry->base_amount) }}
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $entry->commission_base->label() }}</span>
                        </dd>
                    </div>
                    @if ($entry->commission_rate !== null)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Rate</dt>
                            <dd class="text-right tabular-nums text-slate-900 dark:text-white">
                                {{ rtrim(rtrim((string) $entry->commission_rate, '0'), '.') }}%
                            </dd>
                        </div>
                    @endif
                    @if ($entry->fixed_amount !== null)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Fixed amount</dt>
                            <dd class="text-right tabular-nums text-slate-900 dark:text-white">{{ money($entry->fixed_amount) }}</dd>
                        </div>
                    @endif
                    <div class="flex items-start justify-between gap-3 border-t border-slate-200 pt-3 dark:border-slate-800">
                        <dt class="font-medium text-slate-900 dark:text-white">Your commission</dt>
                        <dd class="text-right text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($entry->amount) }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($entry->reversals->isNotEmpty())
                <x-ui.card title="What happened afterwards"
                           subtitle="A refund to the client means the commission on it comes back. The original line stays exactly as it was.">
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Date</th>
                            <th class="px-4 py-3 text-left font-semibold">What</th>
                            <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        </x-slot:head>

                        @foreach ($entry->reversals as $undo)
                            <tr>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($undo->transaction_date) }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :color="$undo->purpose->color()" size="xs">{{ $undo->purpose->label() }}</x-ui.badge>
                                    @if (filled($undo->notes))
                                        <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $undo->notes }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-rose-600 dark:text-rose-400">
                                    −{{ money($undo->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Where it came from">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Reference</dt>
                        <dd class="text-right font-mono text-xs text-slate-900 dark:text-white">{{ $entry->reference }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Business date</dt>
                        <dd class="text-right text-slate-900 dark:text-white">{{ app_date($entry->transaction_date) }}</dd>
                    </div>
                    @if ($entry->studentFeePayment)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Receipt</dt>
                            <dd class="text-right font-mono text-xs text-slate-900 dark:text-white">
                                {{ $entry->studentFeePayment->receipt_no }}
                                <span class="block text-slate-500 dark:text-slate-400">{{ money($entry->studentFeePayment->amount) }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($entry->hold_until !== null)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Available from</dt>
                            <dd class="text-right text-slate-900 dark:text-white">{{ app_date($entry->hold_until) }}</dd>
                        </div>
                    @endif
                    @if ($entry->paid_at !== null)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Paid to you</dt>
                            <dd class="text-right text-slate-900 dark:text-white">{{ app_date($entry->paid_at) }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            @if (bccomp((string) $entry->clawed_back_amount, '0.00', 2) === 1)
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                    <p class="font-semibold">{{ money($entry->clawed_back_amount) }} of this was taken back.</p>
                    <p class="mt-1">
                        It had already been paid to you when the client was refunded, so it could not simply be
                        cancelled — it is owed back and comes off your next commission.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endsection
