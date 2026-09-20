@extends('layouts.admin')

@section('title', 'Monthly attendance')

@php
    $letters = [
        'present' => 'P', 'late' => 'L', 'half_day' => 'H', 'early_leave' => 'E',
        'absent' => 'A', 'on_leave' => 'V', 'holiday' => '·',
    ];
@endphp

@section('header')
    <x-ui.page-header title="Monthly attendance" :subtitle="app_date($month, 'F Y')" icon="table-cells">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.attendance.index', ['date' => $month->toDateString()])" icon="calendar-days">Daily register</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.attendance-summaries.index', ['month' => app_date($month, 'Y-m')])" icon="chart-bar">Summaries</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-ui.form.input type="month" name="month" label="Month" :value="app_date($month, 'Y-m')" />
            </div>
            <div class="w-56">
                <x-ui.form.select name="department_id" label="Department" placeholder="Every department">
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected((int) request('department_id') === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </x-ui.form.select>
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
        </form>
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            P present · L late · E early leave · H half day · A absent · V on leave · · non-working day
        </p>
    </x-ui.card>

    <x-ui.card :title="$employees->count() . ' employee(s)'">
        @if ($employees->isEmpty())
            <x-ui.empty-state icon="table-cells" title="Nobody to show" description="Widen the filters." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="sticky left-0 bg-white px-3 py-2 text-left font-semibold dark:bg-slate-900">Employee</th>
                            @foreach ($dates as $date)
                                <th class="px-1 py-2 text-center font-semibold tabular-nums {{ $date->isWeekend() ? 'text-slate-400' : 'text-slate-600 dark:text-slate-300' }}">
                                    {{ $date->day }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($employees as $employee)
                            @php $days = $rows->get($employee->id, collect()); @endphp
                            <tr>
                                <td class="sticky left-0 bg-white px-3 py-2 dark:bg-slate-900">
                                    <a href="{{ route('admin.employees.show', $employee) }}"
                                       class="whitespace-nowrap font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $employee->name }}</a>
                                </td>
                                @foreach ($dates as $date)
                                    @php $row = $days->get($date->toDateString()); @endphp
                                    <td class="px-1 py-2 text-center">
                                        @if ($row)
                                            <a href="{{ route('admin.attendance.show', $row) }}"
                                               title="{{ $row->status->label() }} · payable {{ app_number((float) $row->payable_factor, 2) }}"
                                               class="inline-block w-5 rounded text-center font-semibold text-{{ $row->status->color() }}-700 dark:text-{{ $row->status->color() }}-300">
                                                {{ $letters[$row->status->value] ?? '?' }}
                                            </a>
                                        @else
                                            <span class="text-slate-300 dark:text-slate-600">–</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
@endsection
