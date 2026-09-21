@extends('layouts.panel')

@section('title', 'Statement')

@php
    $query = request()->query();
@endphp

@section('header')
    <x-ui.page-header title="Your statement"
                      :subtitle="$range->label()"
                      icon="document-text">
        <x-slot:actions>
            <x-ui.button variant="secondary" target="_blank"
                         :href="route('collaborator.statement.export', ['format' => 'print'] + $query)"
                         icon="printer">Print</x-ui.button>
            <x-ui.button variant="secondary"
                         :href="route('collaborator.statement.export', ['format' => 'csv'] + $query)"
                         icon="arrow-down-tray">Download</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card label="Opening" :value="money($statement->opening)" icon="flag" color="slate" />
        <x-ui.stat-card label="Earned" :value="money($statement->credits)" icon="plus-circle" color="emerald" />
        <x-ui.stat-card label="Returned" :value="money($statement->debits)" icon="minus-circle" color="amber" />
        <x-ui.stat-card label="Paid to you" :value="money($statement->payouts)" icon="banknotes" color="sky" />
        <x-ui.stat-card label="Closing" :value="money($statement->closing)" icon="check-circle" color="brand" />
    </div>

    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200">
        <span class="font-semibold">It balances:</span>
        <span class="tabular-nums">{{ $statement->proof() }}</span>
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="technical" value="1" @checked($statement->filters->showTechnicalRows)
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Show adjustments
            </label>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('collaborator.statement.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Movements">
        <x-ui.table :is-empty="$statement->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">What</th>
                <th class="px-4 py-3 text-right font-semibold">In</th>
                <th class="px-4 py-3 text-right font-semibold">Out</th>
                <th class="px-4 py-3 text-right font-semibold">Balance</th>
            </x-slot:head>

            <tr class="bg-slate-50 dark:bg-slate-900/60">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ app_date($range->start()) }}</td>
                <td class="px-4 py-3" colspan="3"><span class="font-medium text-slate-700 dark:text-slate-200">Opening balance</span></td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($statement->opening) }}</td>
            </tr>

            @foreach ($statement->lines as $line)
                <tr>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($line->date) }}</td>
                    <td class="px-4 py-3">
                        <span class="block text-slate-900 dark:text-white">{{ $line->description }}</span>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $line->reference }}</span>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                        {{ bccomp($line->credit, '0.00', 2) === 0 ? '' : money($line->credit) }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-rose-600 dark:text-rose-400">
                        {{ bccomp($line->debit, '0.00', 2) === 0 ? '' : money($line->debit) }}
                    </td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ money($line->balance) }}
                    </td>
                </tr>
            @endforeach

            <tr class="bg-slate-50 dark:bg-slate-900/60">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ app_date($range->end()) }}</td>
                <td class="px-4 py-3" colspan="3"><span class="font-medium text-slate-700 dark:text-slate-200">Closing balance</span></td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($statement->closing) }}</td>
            </tr>

            <x-slot:empty>
                <x-ui.empty-state icon="document-text" title="Nothing moved" :message="$statement->emptyMessage()" />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
