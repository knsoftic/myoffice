@extends('layouts.admin')

@section('title', $collaborator->displayName() . ' — wallet')

@php
    $snapshotFigures = [
        ['Pending approval', $snapshot->pendingBalance, 'Earned, not yet approved. Not payable.'],
        ['Available', $snapshot->availableBalance, 'What could be paid out right now.'],
        ['Reserved', $snapshot->reservedBalance, 'Claimed by a payout that has not been paid.'],
        ['Paid out', $snapshot->paidBalance, 'Settled through a payout that really left the company.'],
    ];
@endphp

@section('header')
    <x-ui.page-header :title="$collaborator->displayName()"
                      :subtitle="'Wallet · ' . $collaborator->collaborator_code"
                      icon="wallet"
                      :back="route('admin.wallets.index')">
        <x-slot:actions>
            @can('collaborator_commissions.view_financial')
                <x-ui.button variant="secondary" :href="route('admin.statements.show', $collaborator)" icon="document-text">
                    Statement
                </x-ui.button>
            @endcan
            @if ($canRecalculate)
                <x-ui.confirm :action="route('admin.wallets.recalculate', $collaborator)"
                              method="POST"
                              variant="warning"
                              icon="arrow-path"
                              title="Recalculate this wallet?"
                              message="The cached figures are rewritten from the ledger. No commission row is touched — a repair moves the copy, never the original."
                              confirm-label="Recalculate">
                    <x-slot:trigger>
                        <x-ui.button type="button" variant="secondary" icon="arrow-path">Recalculate</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
            @if ($canFreeze)
                <x-ui.button variant="{{ $wallet?->is_frozen ? 'primary' : 'danger' }}" icon="lock-closed"
                             x-on:click.prevent="$dispatch('open-modal', 'wallet-freeze')">
                    {{ $wallet?->is_frozen ? 'Unfreeze' : 'Freeze' }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if (! $report->passed())
        <div @class([
            'mb-4 rounded-lg border p-4 text-sm',
            'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200' => $report->structural() !== [],
            'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200' => $report->structural() === [],
        ])>
            <p class="font-semibold">
                {{ $report->structural() === []
                    ? 'The cached figures are out of date. The derived figures below are what the partner is owed.'
                    : 'This ledger disagrees with itself, and no recalculation can fix that.' }}
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($report->findings as $finding)
                    <li>{{ $finding->line() }}</li>
                @endforeach
            </ul>
            @if ($report->structural() !== [])
                <p class="mt-2">
                    Correcting a real shortfall is a <strong>manual adjustment with a written reason</strong>,
                    posted by somebody who holds <code>collaborator_commissions.create</code>. An invented balancing
                    row would destroy the one thing the ledger is for.
                </p>
            @endif
        </div>
    @endif

    @if ($wallet?->is_frozen)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
            <p class="font-semibold">This wallet is frozen. No new payout can be requested or approved.</p>
            <p class="mt-1">Commission still accrues — freezing stops payment, not earning.</p>
            @if (filled($wallet->frozen_reason))
                <p class="mt-1">Reason: {{ $wallet->frozen_reason }}</p>
            @endif
        </div>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($snapshotFigures as [$label, $value, $hint])
            <x-ui.stat-card :label="$label" :value="money($value)"
                            :color="$label === 'Available' ? 'emerald' : 'slate'"
                            :delta-label="$hint" />
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <x-ui.card title="The derivation" subtitle="Spine §6.5.1 — every figure re-derived by summing the ledger, beside the figure the wallet holds.">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Figure</th>
                        <th class="px-4 py-3 text-right font-semibold">Ledger says</th>
                        <th class="px-4 py-3 text-right font-semibold">Cache holds</th>
                        <th class="px-4 py-3 text-right font-semibold">Difference</th>
                    </x-slot:head>

                    @foreach ($snapshot->columns() as $column => $derived)
                        @php
                            $stored = $wallet?->getAttribute($column);
                            $difference = $column === 'ledger_entry_count'
                                ? (int) $derived - (int) ($stored ?? 0)
                                : bcsub((string) ($stored ?? '0.00'), (string) $derived, 2);
                            $agrees = $column === 'ledger_entry_count'
                                ? $difference === 0
                                : bccomp((string) $difference, '0.00', 2) === 0;
                        @endphp
                        <tr>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-200">
                                {{ \Illuminate\Support\Str::of($column)->replace('_', ' ')->ucfirst() }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ $column === 'ledger_entry_count' ? $derived : money($derived) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $wallet === null ? '—' : ($column === 'ledger_entry_count' ? $stored : money($stored)) }}
                            </td>
                            <td @class([
                                'px-4 py-3 text-right tabular-nums',
                                'text-slate-400' => $agrees,
                                'font-semibold text-rose-600 dark:text-rose-400' => ! $agrees,
                            ])>
                                {{ $agrees ? '—' : ($column === 'ledger_entry_count' ? $difference : money($difference)) }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-slot:footer>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        §6.5.2: lifetime {{ money($snapshot->lifetimeEarned) }} = pending + available + reserved + paid
                        = {{ money($snapshot->bucketSum()) }}.
                        {{ $snapshot->identityHolds() ? 'It holds.' : 'It does not hold — something structural is wrong.' }}
                    </p>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="Recent movements" subtitle="The last 15 rows of this partner's ledger.">
                <x-ui.table :is-empty="$recent->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Entry</th>
                        <th class="px-4 py-3 text-left font-semibold">Purpose</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($recent as $entry)
                        <tr>
                            <td class="px-4 py-3">
                                @can('collaborator_commissions.view')
                                    <a href="{{ route('admin.commissions.show', $entry) }}"
                                       class="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                        {{ $entry->reference }}
                                    </a>
                                @else
                                    <span class="font-mono text-xs">{{ $entry->reference }}</span>
                                @endcan
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($entry->transaction_date) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$entry->purpose->color()" size="xs">{{ $entry->purpose->label() }}</x-ui.badge>
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
                        <x-ui.empty-state icon="calculator" title="Nothing earned yet"
                                          message="This partner has no commission rows. A wallet appears the first time money does." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Where things stand">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Awaiting approval</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $pendingApproval }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Payouts in flight</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $inflightPayouts }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Last reconciled</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">
                            {{ $wallet?->last_reconciled_at === null ? 'Never' : app_datetime($wallet->last_reconciled_at) }}
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Checked in</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $report->durationMs }} ms</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Recent payouts">
                <x-ui.table :is-empty="$payouts->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Payout</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    </x-slot:head>

                    @foreach ($payouts as $payout)
                        <tr>
                            <td class="px-4 py-3">
                                @can('collaborator_payouts.view')
                                    <a href="{{ route('admin.payouts.show', $payout) }}"
                                       class="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                        {{ $payout->payout_no }}
                                    </a>
                                @else
                                    <span class="font-mono text-xs">{{ $payout->payout_no }}</span>
                                @endcan
                                <x-ui.badge :color="$payout->status->color()" size="xs" class="mt-1">{{ $payout->status->label() }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($payout->amount) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="banknotes" title="No payouts yet"
                                          message="Nothing has been paid to this partner." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>

    @if ($canFreeze)
        <x-ui.modal name="wallet-freeze" :title="$wallet?->is_frozen ? 'Unfreeze this wallet' : 'Freeze this wallet'">
            <form method="POST" action="{{ route('admin.wallets.freeze', $collaborator) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="frozen" value="{{ $wallet?->is_frozen ? 0 : 1 }}">

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    @if ($wallet?->is_frozen)
                        Payouts become possible again. The reason it was frozen is kept — why it <em>was</em> frozen
                        is the part somebody asks about afterwards.
                    @else
                        Freezing stops new payouts. Commission keeps accruing: a partner under investigation is still
                        owed what they earn, the business simply does not pay it out yet.
                    @endif
                </p>

                @unless ($wallet?->is_frozen)
                    <x-ui.form.textarea name="reason" label="Reason" required rows="3"
                                        placeholder="What the partner is told when they ask." />
                @endunless

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'wallet-freeze')">Cancel</x-ui.button>
                    <x-ui.button type="submit" :variant="$wallet?->is_frozen ? 'primary' : 'danger'">
                        {{ $wallet?->is_frozen ? 'Unfreeze' : 'Freeze' }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
