@extends('layouts.panel')

@section('title', $payout->payout_no)

@php
    $canWithdraw = auth()->user()?->can('collaborator_portal.payout_request')
        && $payout->status === \App\Enums\PayoutStatus::Requested;
@endphp

@section('header')
    <x-ui.page-header :title="money($payout->amount)"
                      :subtitle="$payout->payout_no . ' · ' . $payout->method->label()"
                      icon="banknotes"
                      :badge="$payout->status->label()"
                      :badge-color="$payout->status->color()"
                      :back="route('collaborator.payouts.index')">
        <x-slot:actions>
            @if ($canWithdraw)
                <x-ui.button variant="secondary" icon="arrow-uturn-left"
                             x-on:click.prevent="$dispatch('open-modal', 'withdraw-payout')">
                    Withdraw
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="What this covers"
                       subtitle="The commissions this payout settles, so there is never a question about which ones were paid.">
                <x-ui.table :is-empty="$live->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Commission</th>
                        <th class="px-4 py-3 text-left font-semibold">Earned on</th>
                        <th class="px-4 py-3 text-right font-semibold">Settled here</th>
                    </x-slot:head>

                    @foreach ($live as $allocation)
                        <tr>
                            <td class="px-4 py-3">
                                @can('collaborator_portal.student_commission')
                                    <a href="{{ route('collaborator.commissions.show', $allocation->ledger_entry_id) }}"
                                       class="font-mono text-xs text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                        {{ $allocation->entry?->reference ?? 'CLE-' . $allocation->ledger_entry_id }}
                                    </a>
                                @else
                                    <span class="font-mono text-xs">{{ $allocation->entry?->reference }}</span>
                                @endcan
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($allocation->entry_transaction_date) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($allocation->amount) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="link-slash"
                                          title="Nothing is attached"
                                          message="The commissions this payout held were released — usually because one of them was refunded before it was paid." />
                    </x-slot:empty>
                </x-ui.table>

                @if ($live->isNotEmpty())
                    <x-slot:footer>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            {{ $live->count() }} {{ \Illuminate\Support\Str::plural('commission', $live->count()) }},
                            totalling <span class="font-semibold tabular-nums">{{ money($payout->amount) }}</span>.
                        </p>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            @if ($released->isNotEmpty())
                <x-ui.card title="Removed from this payout"
                           subtitle="Kept on the record so the amount is never unexplained.">
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Commission</th>
                            <th class="px-4 py-3 text-left font-semibold">Why</th>
                            <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        </x-slot:head>

                        @foreach ($released as $allocation)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">
                                    {{ $allocation->entry?->reference ?? 'CLE-' . $allocation->ledger_entry_id }}
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                    {{ $allocation->release_reason?->label() ?? $allocation->release_reason }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500 line-through dark:text-slate-400">
                                    {{ money($allocation->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Where it stands">
                <ol class="space-y-3 text-sm">
                    <li class="flex items-start justify-between gap-3">
                        <span class="text-slate-500 dark:text-slate-400">Requested</span>
                        <span class="text-right text-slate-900 dark:text-white">{{ app_date($payout->requested_at) }}</span>
                    </li>
                    @if ($payout->approved_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Approved</span>
                            <span class="text-right text-slate-900 dark:text-white">{{ app_date($payout->approved_at) }}</span>
                        </li>
                    @endif
                    @if ($payout->paid_on)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Paid</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ app_date($payout->paid_on) }}
                                @if (filled($payout->transaction_id))
                                    <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $payout->transaction_id }}</span>
                                @endif
                            </span>
                        </li>
                    @endif
                    @if ($payout->rejected_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Rejected</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ app_date($payout->rejected_at) }}
                                <span class="block text-xs text-rose-600 dark:text-rose-400">{{ $payout->rejection_reason }}</span>
                            </span>
                        </li>
                    @endif
                    @if ($payout->cancelled_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Withdrawn</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ app_date($payout->cancelled_at) }}
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $payout->cancellation_reason }}</span>
                            </span>
                        </li>
                    @endif
                </ol>
            </x-ui.card>

            <x-ui.card title="Sent to">
                <p class="font-mono text-sm text-slate-900 dark:text-white">{{ $payout->maskedAccount() }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Account details are never shown in full, to you or to anybody else.
                </p>
            </x-ui.card>
        </div>
    </div>

    @if ($canWithdraw)
        <x-ui.modal name="withdraw-payout" title="Withdraw this request" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('collaborator.payouts.cancel', $payout) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The commissions attached to it go straight back to your available balance. You can request a
                    payout again at any time.
                </p>
                <x-ui.form.textarea name="reason" label="Why are you withdrawing it?" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'withdraw-payout')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="secondary">Withdraw</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
