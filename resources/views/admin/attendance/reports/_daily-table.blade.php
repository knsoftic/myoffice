{{--
    One row per class on a date — shared by the attendance landing screen and the daily report, so a
    number cannot read one way on one and another on the other.

    Expects: $report (from AttendanceReportService::daily()), $linkTo ('mark' | 'session' | null).
--}}

@php($linkTo = $linkTo ?? null)

<x-ui.table :is-empty="$report['rows']->isEmpty()">
    <x-slot:head>
        <th class="px-4 py-3 text-left font-semibold">When</th>
        <th class="px-4 py-3 text-left font-semibold">Batch</th>
        <th class="px-4 py-3 text-left font-semibold">Teacher</th>
        <th class="px-4 py-3 text-left font-semibold">Room</th>
        <th class="px-4 py-3 text-left font-semibold">P / A / L / Lt</th>
        <th class="px-4 py-3 text-left font-semibold">%</th>
        <th class="px-4 py-3 text-left font-semibold">Register</th>
    </x-slot:head>

    @foreach ($report['rows'] as $row)
        <tr>
            <td class="px-4 py-3">
                @if ($linkTo === 'mark')
                    <a href="{{ route('admin.student-attendance.mark', $row->id) }}"
                       class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ app_clock($row->start_time) }}</a>
                @else
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ app_clock($row->start_time) }}</span>
                @endif
                <div class="text-xs text-slate-400">{{ app_clock($row->end_time) }}</div>
            </td>
            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                {{ $row->batch_code ?? '—' }}
                <div class="text-xs text-slate-400">{{ $row->course_name }}</div>
            </td>
            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $row->teacher_name ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $row->room_code ?? '—' }}</td>
            <td class="px-4 py-3 text-sm">
                <span class="text-emerald-600 dark:text-emerald-400">{{ app_number($row->present_count) }}</span>
                <span class="text-slate-300">/</span>
                <span class="text-rose-600 dark:text-rose-400">{{ app_number($row->absent_count) }}</span>
                <span class="text-slate-300">/</span>
                <span class="text-sky-600 dark:text-sky-400">{{ app_number($row->leave_count) }}</span>
                <span class="text-slate-300">/</span>
                <span class="text-amber-600 dark:text-amber-400">{{ app_number($row->late_count) }}</span>
                <div class="text-xs text-slate-400">of {{ app_number($row->expected_count) }} expected</div>
            </td>
            <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row->percentage }}%</td>
            <td class="px-4 py-3">
                @if ($row->is_marked)
                    <x-ui.badge color="emerald" size="xs">{{ app_time($row->attendance_marked_at) }}</x-ui.badge>
                    @if ($row->marked_by_name)
                        <div class="mt-0.5 text-xs text-slate-400">{{ $row->marked_by_name }}</div>
                    @endif
                @else
                    <x-ui.badge color="amber" size="xs">Not taken</x-ui.badge>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:empty>
        <x-ui.empty-state icon="calendar-days" title="No classes scheduled on this date"
                          description="Classes come from the timetable. Widen the filters, or pick another day." />
    </x-slot:empty>
</x-ui.table>

@if ($report['rows']->isNotEmpty())
    <div class="border-t border-slate-200/70 px-4 py-3 text-xs text-slate-400 dark:border-slate-800">
        {{ $report['rows']->count() }} {{ \Illuminate\Support\Str::plural('class', $report['rows']->count()) }},
        {{ app_number($report['totals']['unmarked']) }} without a register ·
        {{ $report['percentage'] }}% present overall
        @if ($report['filters'] !== [])
            · filtered by {{ collect($report['filters'])->except('branch_id')->keys()->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ') }}
        @endif
    </div>
@endif
