@extends('layouts.panel')

@section('title', 'Attendance report')

@section('header')
    <x-ui.page-header title="Attendance report"
                      subtitle="Your batches only. Cancelled classes are in no figure here — they did not happen."
                      icon="chart-bar"
                      :back="route('teacher.attendance.index')" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.input name="from" label="From" type="date" :value="$from->toDateString()" />
            <x-ui.form.input name="to" label="To" type="date" :value="$to->toDateString()" />
            <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="By batch" class="mb-4" :padded="false">
        <x-ui.table :is-empty="$report['rows']->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Students</th>
                <th class="px-4 py-3 text-left font-semibold">Held / off</th>
                <th class="px-4 py-3 text-left font-semibold">Average</th>
                <th class="px-4 py-3 text-left font-semibold">Below {{ $report['minimum'] }}%</th>
            </x-slot:head>

            @foreach ($report['rows'] as $row)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $row->code }}</div>
                        <div class="text-xs text-slate-400">{{ $row->course_name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($row->students) }}</td>
                    <td class="px-4 py-3 text-sm">
                        <span class="text-emerald-600 dark:text-emerald-400">{{ app_number($row->sessions_held) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-rose-600 dark:text-rose-400">{{ app_number($row->sessions_cancelled) }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row->average }}%</td>
                    <td class="px-4 py-3 text-sm">
                        @if ((int) $row->below_minimum > 0)
                            <x-ui.badge color="rose" size="xs">{{ app_number($row->below_minimum) }}</x-ui.badge>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="squares-2x2" title="No batches in this range"
                                  description="Widen the dates, or check that you are assigned to a batch." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Students who need a word" :padded="false"
               :subtitle="'Below the institute minimum of '.$percentages['minimum'].'%'">
        @php($below = $percentages['rows']->where('below_minimum', true))

        <x-ui.table :is-empty="$below->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Classes</th>
                <th class="px-4 py-3 text-left font-semibold">%</th>
            </x-slot:head>

            @foreach ($below as $row)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $row->student_name }}</div>
                        <div class="text-xs text-slate-400">{{ $row->student_code }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $row->batch_code }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($row->sessions_expected_count) }}</td>
                    <td class="px-4 py-3 text-sm font-medium text-rose-600 dark:text-rose-400">{{ $row->attendance_percentage }}%</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="check" title="Everybody is above the line"
                                  description="Nobody in your batches is below the institute's minimum attendance." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
