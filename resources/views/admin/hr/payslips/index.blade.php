@extends('layouts.admin')

@section('title', 'Salary slips')

@php
    $canExport = (bool) auth()->user()?->can('salary_slips.export');
@endphp

@section('header')
    <x-ui.page-header title="Salary slips" subtitle="Every slip ever issued, including the corrections." icon="document-text">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" :href="route('admin.payslips.export', ['format' => 'csv'])" icon="arrow-down-tray">Export</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-ui.form.input type="month" name="month" label="Month" :value="$month?->format('Y-m')" />
            </div>
            <div class="w-48">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.payslips.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$slips->total() . ' slip(s)'">
        <x-ui.table :is-empty="$slips->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Slip</th>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Period</th>
                <th class="px-4 py-3 text-right font-semibold">Net</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($slips as $slip)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.payslips.show', $slip) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $slip->slip_number }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $slip->run?->run_number }}
                            @if ($slip->run_type->value === 'correction') · correction @endif
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $slip->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $slip->employee?->employee_code }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $slip->run?->periodLabel() }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-semibold {{ (float) $slip->net_salary < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                        {{ money((string) $slip->net_salary) }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$slip->status->color()" size="xs">{{ $slip->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="document-text" title="No salary slips"
                    description="Slips appear once a payroll run has been generated." />
            </x-slot:empty>
        </x-ui.table>

        @if ($slips->hasPages())
            <div class="mt-4">{{ $slips->links() }}</div>
        @endif
    </x-ui.card>
@endsection
