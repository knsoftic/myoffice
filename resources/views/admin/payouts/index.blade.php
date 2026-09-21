@extends('layouts.admin')

@section('title', 'Payouts')

@php
    $query = request()->query();
@endphp

@section('header')
    <x-ui.page-header title="Payouts"
                      subtitle="What has been paid to partners, and what is waiting. An amount here is the sum of the commissions it settles — never a figure somebody typed."
                      icon="banknotes">
        <x-slot:actions>
            @can('collaborator_payouts.export')
                <x-ui.button variant="secondary" :href="route('admin.payouts.export', ['format' => 'csv'] + $query)" icon="arrow-down-tray">
                    Export
                </x-ui.button>
            @endcan
            @if ($canCreate)
                <x-ui.button variant="primary" :href="route('admin.payouts.create')" icon="plus">New payout</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Awaiting approval" :value="$awaitingApproval" icon="clock"
                        :color="$awaitingApproval > 0 ? 'amber' : 'slate'"
                        delta-label="requested, not yet approved" />
        <x-ui.stat-card label="Awaiting payment" :value="$awaitingPayment" icon="paper-airplane"
                        :color="$awaitingPayment > 0 ? 'sky' : 'slate'"
                        delta-label="approved, money not sent" />
        <x-ui.stat-card label="Reserved" :value="money($reserved)" icon="lock-closed" color="violet"
                        delta-label="claimed by payouts in flight" />
        <x-ui.stat-card label="Paid in range" :value="money($paidInRange)" icon="banknotes" color="emerald"
                        :delta-label="$range->label()" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="Requested from" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="Requested to" :value="request('to', app_date($range->end(), 'Y-m-d'))" />
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Payout no, reference, partner" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="method" label="Method" placeholder="Any method">
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(request('method') === $method->value)>{{ $method->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.payouts.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$payouts->total() . ' ' . \Illuminate\Support\Str::plural('payout', $payouts->total())"
               :subtitle="$range->label()">
        <x-ui.table :is-empty="$payouts->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Payout</th>
                <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
                <th class="px-4 py-3 text-right font-semibold">Requested</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-left font-semibold">Method</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($payouts as $payout)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.payouts.show', $payout) }}"
                           class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $payout->payout_no }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ app_date($payout->requested_at) }}
                            @if ($payout->entry_count > 0)
                                · {{ $payout->entry_count }} {{ \Illuminate\Support\Str::plural('entry', $payout->entry_count) }}
                            @endif
                        </span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-900 dark:text-white">
                            {{ $payout->collaborator?->displayName() ?? 'Collaborator #' . $payout->collaborator_id }}
                        </span>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">
                            {{ $payout->collaborator?->collaborator_code }}
                        </span>
                    </td>

                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ money($payout->requested_amount) }}
                    </td>

                    <td class="px-4 py-3 text-right">
                        <span class="block font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($payout->amount) }}</span>
                        @if ($payout->requestDisagreedWithAvailable())
                            <span class="block text-xs text-amber-600 dark:text-amber-400">less than was asked for</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-700 dark:text-slate-200">{{ $payout->method->label() }}</span>
                        @if (filled($payout->transaction_id))
                            <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $payout->transaction_id }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$payout->status->color()" size="xs">{{ $payout->status->label() }}</x-ui.badge>
                        @if ($payout->paid_on !== null)
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">paid {{ app_date($payout->paid_on) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes"
                                  title="No payouts in this range"
                                  message="A payout claims named commission entries and settles them. Nothing has been raised here yet." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$payouts" label="payouts" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
