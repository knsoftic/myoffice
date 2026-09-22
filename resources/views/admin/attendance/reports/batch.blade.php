@extends('layouts.admin')

@section('title', 'Attendance — by batch')

@section('header')
    <x-ui.page-header title="Attendance reports"
                      subtitle="One row per batch: what was planned, what was held, what was called off, and how the class is doing."
                      icon="squares-2x2"
                      :back="route('admin.student-attendance.index')" />
@endsection

@section('content')
    <x-ui.card class="mb-4" :padded="false">
        @include('admin.attendance.reports._tabs', ['active' => 'batch'])

        <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.input name="from" label="From" type="date" :value="$from->toDateString()" />
            <x-ui.form.input name="to" label="To" type="date" :value="$to->toDateString()" />

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Anybody">
                @foreach ($teachers as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('teacher_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Batch status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-attendance.reports.batch')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$report['rows']->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Teacher</th>
                <th class="px-4 py-3 text-left font-semibold">Students</th>
                <th class="px-4 py-3 text-left font-semibold">Planned / held / off</th>
                <th class="px-4 py-3 text-left font-semibold">Average</th>
                <th class="px-4 py-3 text-left font-semibold">Below line</th>
                <th class="px-4 py-3 text-left font-semibold">Syllabus</th>
                <th class="px-4 py-3 text-left font-semibold">Last class</th>
            </x-slot:head>

            @foreach ($report['rows'] as $row)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.student-attendance.reports.monthly', ['batch_id' => $row->id]) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $row->code }}</a>
                        <div class="text-xs text-slate-400">{{ $row->course_name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $row->teacher_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($row->students) }}</td>
                    <td class="px-4 py-3 text-sm">
                        {{ app_number($row->sessions_planned) }}
                        <span class="text-slate-300">/</span>
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
                    <td class="px-4 py-3">
                        <div class="text-sm text-slate-600 dark:text-slate-300">{{ app_number($row->syllabus_completion_percentage) }}%</div>
                        <div class="mt-1 h-1.5 w-20 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-violet-500" style="width: {{ min(100, (float) $row->syllabus_completion_percentage) }}%"></div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $row->last_session_on ? app_date($row->last_session_on) : '—' }}
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="squares-2x2" title="No batches in this range"
                                  description="Widen the dates, or clear the course and teacher filters." />
            </x-slot:empty>
        </x-ui.table>

        <div class="border-t border-slate-200/70 px-4 py-3 text-xs text-slate-400 dark:border-slate-800">
            {{ app_date($from) }} – {{ app_date($to) }} · the line is {{ $report['minimum'] }}% ·
            planned counts every class in the window, held counts the ones that happened.
        </div>
    </x-ui.card>
@endsection
