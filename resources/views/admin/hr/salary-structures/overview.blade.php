@extends('layouts.admin')

@section('title', 'Salary structures')

@section('header')
    <x-ui.page-header title="Salary structures" subtitle="Who is on what, today." icon="banknotes" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            A rate is <strong class="font-semibold text-slate-900 dark:text-white">never edited</strong>. A raise
            closes the version in force the day before the new one starts and inserts the next one, so a slip from
            last March still points at what March actually paid.
        </p>
    </x-ui.card>

    <x-ui.card :title="$employees->total() . ' employee(s)'">
        <x-ui.table :is-empty="$employees->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Department</th>
                <th class="px-4 py-3 text-right font-semibold">Basic</th>
                <th class="px-4 py-3 text-right font-semibold">Gross</th>
                <th class="px-4 py-3 text-left font-semibold">In force from</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($employees as $employee)
                @php $structure = $employee->activeSalaryStructure; @endphp
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.employees.show', $employee) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $employee->name }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $employee->employee_code }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $employee->department?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $structure ? money((string) $structure->basic_salary) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">
                        {{ $structure ? money((string) $structure->gross_salary) : '—' }}
                    </td>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($structure)
                            {{ app_date($structure->effective_from) }}
                            <span class="block text-xs text-slate-500 dark:text-slate-400">version {{ $structure->version }}</span>
                        @else
                            <span class="text-xs text-rose-600 dark:text-rose-400">no structure — payroll will skip them by name</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button variant="ghost" size="sm" :href="route('admin.employees.salary-structures.index', $employee)">Timeline</x-ui.button>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes" title="Nobody to show" />
            </x-slot:empty>
        </x-ui.table>

        @if ($employees->hasPages())
            <div class="mt-4">{{ $employees->links() }}</div>
        @endif
    </x-ui.card>
@endsection
