@extends('layouts.admin')

@section('title', 'My salary slips')

@section('header')
    <x-ui.page-header title="My salary slips" subtitle="Every slip issued to you, including corrections." icon="document-text" />
@endsection

@section('content')
    <x-ui.card :title="$slips->count() . ' slip(s)'">
        <x-ui.table :is-empty="$slips->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Slip</th>
                <th class="px-4 py-3 text-left font-semibold">Period</th>
                <th class="px-4 py-3 text-right font-semibold">Net</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($slips as $slip)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.my.payslips.show', $slip) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $slip->slip_number }}</a>
                        @if ($slip->run_type->value === 'correction')
                            <span class="block text-xs text-slate-500 dark:text-slate-400">correction</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $slip->run?->periodLabel() }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $slip->net_salary) }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$slip->status->color()" size="xs">{{ $slip->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="document-text" title="No slips yet"
                    description="A slip appears once the month's payroll has been generated." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
