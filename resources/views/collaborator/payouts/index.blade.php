@extends('layouts.panel')

@section('title', 'Payouts')

@section('header')
    <x-ui.page-header title="Your payouts"
                      subtitle="What has been paid to you, and what is on its way."
                      icon="banknotes">
        <x-slot:actions>
            @can('collaborator_portal.payout_request')
                <x-ui.button variant="secondary" :href="route('collaborator.payout-accounts.index')" icon="credit-card">
                    Bank accounts
                </x-ui.button>
            @endcan
            @if ($canRequest)
                <x-ui.button variant="primary" :href="route('collaborator.payouts.create')" icon="plus">
                    Request a payout
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Available now" :value="money($snapshot->availableBalance)" icon="wallet"
                        :color="$snapshot->isInDebit() ? 'rose' : 'emerald'" />
        <x-ui.stat-card label="Being processed" :value="money($snapshot->reservedBalance)" icon="clock" color="sky" />
        <x-ui.stat-card label="Paid to date" :value="money($totalPaid)" icon="check-circle" color="slate" />
    </div>

    <x-ui.card :title="$payouts->total() . ' ' . \Illuminate\Support\Str::plural('payout', $payouts->total())">
        <x-ui.table :is-empty="$payouts->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Payout</th>
                <th class="px-4 py-3 text-left font-semibold">Requested</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-left font-semibold">State</th>
            </x-slot:head>

            @foreach ($payouts as $payout)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('collaborator.payouts.show', $payout) }}"
                           class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $payout->payout_no }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $payout->method->label() }}</span>
                    </td>

                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($payout->requested_at) }}</td>

                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ money($payout->amount) }}
                    </td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$payout->status->color()" size="xs">{{ $payout->status->label() }}</x-ui.badge>
                        @if ($payout->paid_on !== null)
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">paid {{ app_date($payout->paid_on) }}</span>
                        @elseif (filled($payout->rejection_reason))
                            <span class="mt-1 block text-xs text-rose-600 dark:text-rose-400">{{ $payout->rejection_reason }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes"
                                  title="No payouts yet"
                                  message="When you have an available balance you can ask to be paid, and the request appears here." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$payouts" label="payouts" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
