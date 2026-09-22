@extends('layouts.admin')

@section('title', 'Attendance — by student')

@section('header')
    <x-ui.page-header title="Attendance reports"
                      subtitle="One row per enrolment, lowest first. Anybody below the institute's line is flagged — nothing is refused by it."
                      icon="users"
                      :back="route('admin.student-attendance.index')" />
@endsection

@section('content')
    <x-ui.card class="mb-4" :padded="false">
        @include('admin.attendance.reports._tabs', ['active' => 'percentage'])

        <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Enrolment" placeholder="Active only">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="min_percentage" label="From %" type="number" min="0" max="100" :value="request('min_percentage')" />
            <x-ui.form.input name="max_percentage" label="To %" type="number" min="0" max="100" :value="request('max_percentage')" />

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-attendance.reports.percentage')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Enrolments" :value="app_number($report['rows']->count())" icon="users" color="slate" />
        <x-ui.stat-card label="Below {{ $report['minimum'] }}%" :value="app_number($report['below'])" icon="exclamation-triangle" color="rose" />
        <x-ui.stat-card label="Average" :value="$report['average'].'%'" icon="chart-bar" color="brand" />
    </div>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$report['rows']->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Classes</th>
                <th class="px-4 py-3 text-left font-semibold">P / A / L / Lt</th>
                <th class="px-4 py-3 text-left font-semibold">%</th>
            </x-slot:head>

            @foreach ($report['rows'] as $row)
                <tr class="{{ $row->below_minimum ? 'bg-rose-50/50 dark:bg-rose-500/5' : '' }}">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.students.show', $row->student_id) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $row->student_name }}</a>
                        <div class="text-xs text-slate-400">{{ $row->student_code }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $row->batch_code }}
                        <div class="text-xs text-slate-400">{{ $row->course_name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($row->sessions_expected_count) }}</td>
                    <td class="px-4 py-3 text-sm">
                        <span class="text-emerald-600 dark:text-emerald-400">{{ app_number($row->present_count) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-rose-600 dark:text-rose-400">{{ app_number($row->absent_count) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-sky-600 dark:text-sky-400">{{ app_number($row->leave_count) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-amber-600 dark:text-amber-400">{{ app_number($row->late_count) }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ $row->attendance_percentage }}%</span>
                            @if ($row->below_minimum)
                                <x-ui.badge color="rose" size="xs">Below {{ $report['minimum'] }}%</x-ui.badge>
                            @elseif ((int) $row->sessions_expected_count === 0)
                                <x-ui.badge color="slate" size="xs">No classes yet</x-ui.badge>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="users" title="No enrolments match"
                                  description="Widen the filters, or check that the batch has students in it." />
            </x-slot:empty>
        </x-ui.table>

        <div class="border-t border-slate-200/70 px-4 py-3 text-xs text-slate-400 dark:border-slate-800">
            A student with no classes yet is not below the minimum — they are unmeasured, and flagging
            them would put somebody on a warning list for the timetable's sake.
        </div>
    </x-ui.card>
@endsection
