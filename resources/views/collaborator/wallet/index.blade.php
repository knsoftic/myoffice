@extends('layouts.panel')

@section('title', 'Wallet')

@php
    $available = $snapshot->availableBalance;
    $inDebit = $snapshot->isInDebit();
    $belowMinimum = ! $inDebit && bccomp($available, $minimum, 2) === -1;
@endphp

@section('header')
    <x-ui.page-header title="Your wallet"
                      subtitle="What you have earned, what is waiting, and what could be paid to you today."
                      icon="wallet">
        <x-slot:actions>
            @can('collaborator_portal.statement_download')
                <x-ui.button variant="secondary" :href="route('collaborator.statement.index')" icon="document-text">
                    Statement
                </x-ui.button>
            @endcan
            @if ($canRequest && ! $inDebit && ! $belowMinimum && bccomp($available, '0.00', 2) === 1)
                <x-ui.button variant="primary" :href="route('collaborator.payouts.create')" icon="banknotes">
                    Request a payout
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($wallet?->is_frozen)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-semibold">Payouts are paused on your account.</p>
            <p class="mt-1">
                You keep earning commission as normal — this only stops new payouts.
                @if (filled($wallet->frozen_reason))
                    Reason: {{ $wallet->frozen_reason }}
                @endif
                Please speak to the office.
            </p>
        </div>
    @endif

    @if ($inDebit)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
            <p class="font-semibold">Your balance is {{ money($available) }}.</p>
            <p class="mt-1">
                A commission that had already been paid to you was later refunded by the client, so it has been
                taken back. New commission you earn will clear this first, and no payout can be requested until
                it does. Every line is on your statement with the receipt it came from.
            </p>
        </div>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Available now" :value="money($available)" icon="banknotes"
                        :color="$inDebit ? 'rose' : 'emerald'"
                        delta-label="you can ask for this" />
        <x-ui.stat-card label="Waiting for approval" :value="money($snapshot->pendingBalance)" icon="clock" color="amber"
                        delta-label="earned, not approved yet" />
        <x-ui.stat-card label="Reserved" :value="money($snapshot->reservedBalance)" icon="lock-closed" color="sky"
                        delta-label="on a payout being processed" />
        <x-ui.stat-card label="Paid to you" :value="money($snapshot->paidBalance)" icon="check-circle" color="slate"
                        :delta-label="$lastPaidAt === null ? 'nothing yet' : 'last on ' . app_date($lastPaidAt)" />
    </div>

    @if ($belowMinimum)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            The minimum payout is {{ money($minimum) }}. You have {{ money($available) }} available, so the next
            {{ money(bcsub($minimum, $available, 2)) }} you earn will make it possible to request one.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Recent commission"
                       subtitle="The last ten movements on your account.">
                <x-ui.table :is-empty="$recent->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">What it was for</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th class="px-4 py-3 text-left font-semibold">State</th>
                    </x-slot:head>

                    @foreach ($recent as $entry)
                        <tr>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($entry->transaction_date) }}</td>
                            <td class="px-4 py-3">
                                <span class="block text-slate-900 dark:text-white">{{ $entry->purpose->label() }}</span>
                                @if (filled($entry->notes))
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $entry->notes }}</span>
                                @endif
                            </td>
                            <td @class([
                                'px-4 py-3 text-right font-semibold tabular-nums',
                                'text-rose-600 dark:text-rose-400' => str_starts_with((string) $entry->signed_amount, '-'),
                                'text-slate-900 dark:text-white' => ! str_starts_with((string) $entry->signed_amount, '-'),
                            ])>{{ money($entry->signed_amount) }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$entry->status->color()" size="xs">{{ $entry->status->label() }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calculator"
                                          title="Nothing yet"
                                          message="Commission appears here when a referral of yours pays — never when they register." />
                    </x-slot:empty>
                </x-ui.table>

                @can('collaborator_portal.student_commission')
                    <x-slot:footer>
                        <x-ui.button size="sm" variant="ghost" :href="route('collaborator.commissions.index')">
                            See every commission
                        </x-ui.button>
                    </x-slot:footer>
                @endcan
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="What these mean">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-medium text-slate-900 dark:text-white">Waiting for approval</dt>
                        <dd class="text-slate-600 dark:text-slate-300">
                            The money arrived and your commission was worked out. Somebody in the office still has
                            to approve it.
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-900 dark:text-white">Available</dt>
                        <dd class="text-slate-600 dark:text-slate-300">Approved and yours to ask for.</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-900 dark:text-white">Reserved</dt>
                        <dd class="text-slate-600 dark:text-slate-300">
                            Attached to a payout that is being processed. It is still yours; it is just spoken for.
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-900 dark:text-white">Paid</dt>
                        <dd class="text-slate-600 dark:text-slate-300">Already sent to you.</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($inFlight->isNotEmpty())
                <x-ui.card title="Payouts being processed">
                    <ul class="divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        @foreach ($inFlight as $payout)
                            <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                <a href="{{ route('collaborator.payouts.show', $payout) }}"
                                   class="font-mono text-xs text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">
                                    {{ $payout->payout_no }}
                                </a>
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($payout->amount) }}</span>
                                    <x-ui.badge :color="$payout->status->color()" size="xs">{{ $payout->status->label() }}</x-ui.badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
