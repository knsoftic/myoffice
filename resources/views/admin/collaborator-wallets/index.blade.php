@extends('layouts.admin')

@section('title', 'Collaborator wallets')

@php
    $query = request()->query();
@endphp

@section('header')
    <x-ui.page-header title="Collaborator wallets"
                      subtitle="A wallet is a cache of the ledger. Every figure here is re-derivable by summing it, and the reconciler proves it nightly."
                      icon="wallet">
        <x-slot:actions>
            @can('wallet_reconciliation.view_any')
                <x-ui.button variant="secondary" :href="route('admin.wallet-reconciliations.index')" icon="scale">
                    Reconciliations
                </x-ui.button>
            @endcan
            @can('collaborator_payouts.view_any')
                <x-ui.button variant="secondary" :href="route('admin.payouts.index')" icon="banknotes">
                    Payouts
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($driftingCount > 0)
        {{-- §6.5.4: the screens say so until somebody presses Recalculate. Silence would be the system
             showing figures it already knows it cannot reproduce. --}}
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
            <p class="font-semibold">
                {{ $driftingCount }} {{ \Illuminate\Support\Str::plural('wallet', $driftingCount) }}
                {{ $driftingCount === 1 ? 'does' : 'do' }} not match the ledger.
            </p>
            <p class="mt-1">
                Their cached figures are shown below for comparison, but the ledger is what the partner is owed.
                Open a wallet to see the derivation beside the cache.
                @can('wallet_reconciliation.view_any')
                    <a href="{{ route('admin.wallet-reconciliations.index', ['problems' => 1]) }}" class="font-semibold underline">
                        See what disagreed
                    </a>
                @endcan
            </p>
        </div>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Available now" :value="money($totals['available'])" icon="banknotes" color="emerald"
                        delta-label="payable today" />
        <x-ui.stat-card label="Awaiting approval" :value="money($totals['pending'])" icon="clock" color="amber"
                        delta-label="earned, not yet payable" />
        <x-ui.stat-card label="Reserved by payouts" :value="money($totals['reserved'])" icon="lock-closed" color="sky"
                        delta-label="claimed, not yet paid" />
        <x-ui.stat-card label="Lifetime earned" :value="money($totals['lifetime'])" icon="chart-bar" color="slate"
                        delta-label="credits less reversals" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name, company or code" />

            <x-ui.form.select name="status" label="Reconciliation" placeholder="Any">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="frozen" value="1" @checked(request()->boolean('frozen'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Frozen only
            </label>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                {{-- A negative available balance is a real debt after a clawback, and it is the one
                     thing on this screen somebody has to act on. --}}
                <input type="checkbox" name="owing" value="1" @checked(request()->boolean('owing'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Owing the business
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.wallets.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$wallets->total() . ' ' . \Illuminate\Support\Str::plural('wallet', $wallets->total())"
               :subtitle="$lastRunAt === null ? 'No reconciliation has run yet.' : 'Last reconciled ' . app_datetime($lastRunAt) . '.'">
        <x-ui.table :is-empty="$wallets->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
                <x-ui.th-sortable column="available" :sort="$sort" :direction="$direction" default="desc" align="right" numeric>Available</x-ui.th-sortable>
                <x-ui.th-sortable column="pending" :sort="$sort" :direction="$direction" default="desc" align="right" numeric>Pending</x-ui.th-sortable>
                <x-ui.th-sortable column="reserved" :sort="$sort" :direction="$direction" default="desc" align="right" numeric>Reserved</x-ui.th-sortable>
                <x-ui.th-sortable column="paid" :sort="$sort" :direction="$direction" default="desc" align="right" numeric>Paid out</x-ui.th-sortable>
                <x-ui.th-sortable column="lifetime" :sort="$sort" :direction="$direction" default="desc" align="right" numeric>Lifetime</x-ui.th-sortable>
                <th class="px-4 py-3 text-left font-semibold">State</th>
            </x-slot:head>

            @foreach ($wallets as $wallet)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.wallets.show', $wallet->collaborator_id) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $wallet->collaborator?->displayName() ?? 'Collaborator #' . $wallet->collaborator_id }}
                        </a>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">
                            {{ $wallet->collaborator?->collaborator_code }}
                            @if ($wallet->collaborator?->trashed())
                                · deleted, and still owed
                            @endif
                        </span>
                    </td>

                    <td class="px-4 py-3 text-right">
                        <span @class([
                            'font-semibold tabular-nums',
                            'text-rose-600 dark:text-rose-400' => bccomp((string) $wallet->available_balance, '0.00', 2) === -1,
                            'text-slate-900 dark:text-white' => bccomp((string) $wallet->available_balance, '0.00', 2) !== -1,
                        ])>{{ money($wallet->available_balance) }}</span>
                        @if (bccomp((string) $wallet->available_balance, '0.00', 2) === -1)
                            <span class="block text-xs text-rose-600 dark:text-rose-400">owed back after a clawback</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($wallet->pending_balance) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($wallet->reserved_balance) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($wallet->paid_balance) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($wallet->lifetime_earned) }}</td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$wallet->reconciliation_status->color()" size="xs">
                            {{ $wallet->reconciliation_status->label() }}
                        </x-ui.badge>
                        @if ($wallet->is_frozen)
                            <x-ui.badge color="rose" size="xs" class="mt-1">Frozen</x-ui.badge>
                        @endif
                        @if (bccomp((string) $wallet->drift_amount, '0.00', 2) === 1)
                            <span class="mt-1 block text-xs text-amber-600 dark:text-amber-400">
                                out by {{ money($wallet->drift_amount) }}
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="wallet"
                                  title="No wallets yet"
                                  message="A wallet appears the first time a partner earns something — a table full of zero rows is a table somebody eventually sums." />
            </x-slot:empty>
            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$wallets" label="wallets" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
