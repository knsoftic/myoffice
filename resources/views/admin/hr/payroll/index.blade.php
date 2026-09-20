@extends('layouts.admin')

@section('title', 'Payroll runs')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('payroll.create');
@endphp

@section('header')
    <x-ui.page-header title="Payroll" subtitle="Draft, generated, locked, paid — and never back again." icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payslips.index')" icon="document-text">Salary slips</x-ui.button>
            @if ($canCreate)
                <x-ui.button :href="route('admin.payroll-runs.create')" icon="plus">New run</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-56">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.payroll-runs.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$runs->total() . ' run(s)'">
        <x-ui.table :is-empty="$runs->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Run</th>
                <th class="px-4 py-3 text-left font-semibold">Period</th>
                <th class="px-4 py-3 text-right font-semibold">Slips</th>
                @if ($showMoney)
                    <th class="px-4 py-3 text-right font-semibold">Net</th>
                    <th class="px-4 py-3 text-right font-semibold">Paid</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($runs as $run)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.payroll-runs.show', $run) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $run->run_number }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $run->run_type->label() }}
                            @if ($run->branch) · {{ $run->branch->name }} @endif
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $run->periodLabel() }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $run->items_count }}</td>
                    @if ($showMoney)
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $run->total_net) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $run->total_paid) }}</td>
                    @endif
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$run->status->color()" size="xs">{{ $run->status->label() }}</x-ui.badge>
                        @if ($run->locked_at)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">locked by {{ $run->locker?->name }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes" title="No payroll runs yet"
                    description="A run needs an attendance summary for every employee it pays — it refuses to guess." />
            </x-slot:empty>
        </x-ui.table>

        @if ($runs->hasPages())
            <div class="mt-4">{{ $runs->links() }}</div>
        @endif
    </x-ui.card>
@endsection
