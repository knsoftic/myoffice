@extends('layouts.admin')

@section('title', 'Attendance register')

@php
    $user = auth()->user();
@endphp

@section('header')
    <x-ui.page-header title="Attendance" :subtitle="app_date($date, 'l, j F Y')" icon="calendar-days">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.attendance.monthly', ['month' => app_date($date, 'Y-m')])" icon="table-cells">Monthly grid</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.attendance-corrections.index')" icon="wrench-screwdriver">Corrections</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-end gap-3">
            <form method="GET" class="flex flex-1 flex-wrap items-end gap-3">
                <div class="w-44">
                    <x-ui.form.input type="date" name="date" label="Date" :value="$date->toDateString()" />
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

            @if ($canMark)
                <form method="POST" action="{{ route('admin.attendance.close') }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <x-ui.button type="submit" variant="ghost" icon="check-circle">Close this day</x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card :title="$employees->count() . ' employee(s)'"
               subtitle="Everybody who could work this day, including the ones with no row yet.">
        <x-ui.table :is-empty="$employees->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Day</th>
                <th class="px-4 py-3 text-left font-semibold">In / out</th>
                <th class="px-4 py-3 text-right font-semibold">Worked</th>
                <th class="px-4 py-3 text-right font-semibold">Late</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-right font-semibold">Payable</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($employees as $employee)
                @php $row = $rows->get($employee->id); @endphp
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.employees.show', $employee) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $employee->name }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $employee->employee_code }}
                            @if ($employee->is_attendance_exempt) · exempt @endif
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                        {{ ($row?->day_type ?? $dayTypes[$employee->id])->label() }}
                    </td>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($row?->check_in_at)
                            {{ app_time($row->check_in_at, 'H:i') }} – {{ $row->check_out_at ? app_time($row->check_out_at, 'H:i') : '…' }}
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $row ? intdiv($row->worked_minutes, 60).'h '.($row->worked_minutes % 60).'m' : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums {{ ($row?->late_minutes ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-600 dark:text-slate-300' }}">
                        {{ $row?->late_minutes ? $row->late_minutes.'m' : '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if ($row)
                            <x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge>
                            @if ($row->requires_correction)
                                <span class="ml-1 text-xs text-rose-600 dark:text-rose-400">needs a correction</span>
                            @endif
                            @if ($row->is_manual)
                                <span class="ml-1 text-xs text-slate-500 dark:text-slate-400">manual</span>
                            @endif
                        @else
                            <span class="text-xs text-slate-400">no row yet</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $row ? app_number((float) $row->payable_factor, 2) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($row)
                            <x-ui.button variant="ghost" size="sm" :href="route('admin.attendance.show', $row)">Open</x-ui.button>
                        @elseif ($canMark)
                            <form method="POST" action="{{ route('admin.attendance.punch') }}">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                <input type="hidden" name="direction" value="in">
                                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                <x-ui.button type="submit" variant="ghost" size="sm">Check in</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="calendar-days" title="Nobody to show"
                    description="Either no employees had joined by this date, or the filters exclude them all." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
