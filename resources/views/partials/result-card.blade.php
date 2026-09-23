{{--
    The printed result card (phase-19-23 §5.1, §7.3, §7.4).

    **One template, two callers.** The office prints it from `admin.result-cards.show` and the student
    prints it from `student.results.card`. They must produce the same document: a student holding a
    card that differs from the office's copy is a support ticket at best. The totals come from
    `ResultSummary` for the same reason.

    **Published results only reach it.** Both controllers scope their query that way; nothing here
    re-checks, because a partial that filters is a partial whose callers stop filtering.

    Expects: $student, $enrollment, $results, $summary, $consolidated, $showPosition, $showAttendance.
--}}
<article>
    <header class="border-b border-slate-300 pb-4">
        <h1 class="text-2xl font-semibold">Result card</h1>
        <div class="mt-3 grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
            <div><span class="text-slate-500">Student:</span> <strong>{{ $student->name }}</strong></div>
            <div><span class="text-slate-500">Roll number:</span> {{ $student->student_code }}</div>
            <div><span class="text-slate-500">Course:</span> {{ $enrollment->batch?->course?->name ?? '—' }}</div>
            <div><span class="text-slate-500">Batch:</span> {{ $enrollment->batch?->code ?? '—' }}</div>
        </div>
    </header>

    <table class="mt-6 w-full border-collapse text-sm">
        <thead>
            <tr class="border-b border-slate-300 text-left">
                <th class="py-2 pr-3 font-semibold">Exam</th>
                <th class="py-2 pr-3 font-semibold">Date</th>
                @if ($showAttendance)
                    <th class="py-2 pr-3 font-semibold">Attendance</th>
                @endif
                <th class="py-2 pr-3 text-right font-semibold">Marks</th>
                <th class="py-2 pr-3 text-right font-semibold">%</th>
                <th class="py-2 pr-3 font-semibold">Grade</th>
                @if ($showPosition)
                    <th class="py-2 text-right font-semibold">Position</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($results as $result)
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-3">
                        {{ $result->exam?->name ?? '—' }}
                        <div class="text-xs text-slate-500">{{ $result->exam?->exam_type?->label() }}</div>
                    </td>
                    <td class="py-2 pr-3">{{ app_date($result->exam?->scheduled_date) }}</td>
                    @if ($showAttendance)
                        <td class="py-2 pr-3">{{ $result->attendance_status->label() }}</td>
                    @endif
                    <td class="py-2 pr-3 text-right tabular-nums">
                        @if ($result->obtained_marks === null)
                            —
                        @else
                            {{ app_number($result->obtained_marks, 2) }} / {{ app_number($result->total_marks, 2) }}
                        @endif
                    </td>
                    <td class="py-2 pr-3 text-right tabular-nums">
                        {{ $result->percentage === null ? '—' : app_number($result->percentage, 2) }}
                    </td>
                    <td class="py-2 pr-3">{{ $result->grade ?? '—' }}</td>
                    @if ($showPosition)
                        <td class="py-2 text-right tabular-nums">{{ app_ordinal($result->position_in_batch) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if ($consolidated)
            <tfoot>
                <tr class="border-t-2 border-slate-400 font-semibold">
                    <td class="py-2 pr-3" colspan="{{ 2 + ($showAttendance ? 1 : 0) }}">Overall</td>
                    <td class="py-2 pr-3 text-right tabular-nums">
                        {{ app_number($summary['obtained'], 2) }} / {{ app_number($summary['total'], 2) }}
                    </td>
                    <td class="py-2 pr-3 text-right tabular-nums">
                        {{ $summary['percentage'] === null ? '—' : app_number($summary['percentage'], 2) }}
                    </td>
                    <td class="py-2 pr-3" colspan="{{ 1 + ($showPosition ? 1 : 0) }}">
                        @if ($summary['gradePoints'] !== null)
                            GPA {{ app_number($summary['gradePoints'], 2) }}
                        @endif
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($consolidated)
        <p class="mt-4 text-xs text-slate-500">
            {{ app_number($summary['passed']) }} of {{ app_number($summary['counted']) }} exams passed.
            An exam a student was excused is left out of the total altogether; an absence counts against it.
            The grade-point average covers the exams that carry a grade, so a paper nobody marked is not
            counted as a zero.
        </p>
    @endif

    <footer class="mt-10 grid grid-cols-2 gap-8 text-xs text-slate-500">
        <div class="border-t border-slate-300 pt-2">Examination officer</div>
        <div class="border-t border-slate-300 pt-2">Date</div>
    </footer>
</article>
