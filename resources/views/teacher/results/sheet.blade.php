@extends('layouts.panel')

@section('title', 'Marks — '.$exam->name)

@section('header')
    <x-ui.page-header :title="$exam->name" icon="pencil-square"
                      :subtitle="($exam->batch?->code ?? '').' · '.app_date($exam->scheduled_date).' · out of '.app_number($exam->total_marks, 2)">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('teacher.results.index')">All marking</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        The same grid and the same POST as the admin panel — and no verify or publish button, which is
        §2.28.4 rather than an omission. The person who marked a sheet is not the person who signs it
        off; a button here would be one that always failed.
    --}}
    <x-ui.card class="mb-4">
        <div class="flex gap-3">
            <x-ui.icon name="information-circle" class="h-5 w-5 shrink-0 text-slate-400" />
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Marks are graded against <span class="font-medium text-slate-700 dark:text-slate-200">{{ $scale->code }}</span>,
                with the pass line at {{ app_number($exam->passing_marks, 2) }}.
                The sheet saves together or not at all — an error comes back against the student who caused it and
                leaves everything else in the boxes. Somebody other than you checks it before the class sees anything.
            </p>
        </div>
    </x-ui.card>

    @if ($readonly)
        <x-ui.card class="mb-4 border-slate-200 dark:border-slate-700">
            <div class="flex gap-3">
                <x-ui.icon name="lock-closed" class="h-5 w-5 shrink-0 text-slate-400" />
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    This sheet is closed — the exam is {{ mb_strtolower($exam->status->label()) }}. A published mark is
                    corrected by the office, with a reason on the record.
                </p>
            </div>
        </x-ui.card>
    @endif

    <form method="POST" action="{{ route('teacher.results.save', $exam) }}">
        @csrf

        <x-ui.card :padded="false" class="mb-4">
            <x-ui.table :is-empty="$rows->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Student</th>
                    <th class="px-4 py-3 text-left font-semibold">Attendance</th>
                    <th class="px-4 py-3 text-right font-semibold">Marks</th>
                    <th class="px-4 py-3 text-left font-semibold">Grade</th>
                    <th class="px-4 py-3 text-left font-semibold">Remarks</th>
                </x-slot:head>

                @foreach ($rows as $index => $row)
                    @php($result = $row['result'])
                    <tr @class(['bg-rose-50/40 dark:bg-rose-500/5' => $errors->has("rows.$index.obtained_marks")])>
                        <td class="px-4 py-3">
                            <input type="hidden" name="rows[{{ $index }}][student_id]" value="{{ $row['student_id'] }}">
                            <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row['student']?->name ?? '—' }}</div>
                            <div class="text-xs text-slate-400">{{ $row['student']?->student_code }}</div>
                        </td>

                        <td class="px-4 py-3">
                            <select name="rows[{{ $index }}][attendance_status]"
                                    @disabled($readonly)
                                    class="w-36 rounded-lg border-slate-300 text-sm disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                                @foreach (App\Enums\ExamAttendanceStatus::cases() as $case)
                                    <option value="{{ $case->value }}"
                                            @selected(old("rows.$index.attendance_status", $row['attendance_status']->value) === $case->value)>
                                        {{ $case->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </td>

                        <td class="px-4 py-3 text-right">
                            <input type="number" step="0.01" min="0" max="{{ $exam->total_marks }}"
                                   name="rows[{{ $index }}][obtained_marks]"
                                   value="{{ old("rows.$index.obtained_marks", $row['obtained_marks']) }}"
                                   @disabled($readonly)
                                   placeholder="—"
                                   class="w-24 rounded-lg border-slate-300 text-right text-sm tabular-nums disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            @error("rows.$index.obtained_marks")
                                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
                            @enderror
                        </td>

                        <td class="px-4 py-3">
                            @if ($result && $result->grade)
                                <x-ui.badge :color="$result->band?->color ?? 'slate'" size="xs">{{ $result->grade }}</x-ui.badge>
                                <div class="mt-1 text-xs text-slate-400">{{ app_number($result->percentage, 2) }}%</div>
                            @else
                                <span class="text-xs text-slate-400">on save</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <input type="text" maxlength="500"
                                   name="rows[{{ $index }}][remarks]"
                                   value="{{ old("rows.$index.remarks", $row['remarks']) }}"
                                   @disabled($readonly)
                                   class="w-full rounded-lg border-slate-300 text-sm disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="user-group" title="Nobody on the roster"
                                      description="The roster is taken as it stood on the exam's own date, not today's." />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>

        @unless ($readonly || $rows->isEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-400">
                    An absence is not a zero — leave the marks box empty and set the attendance instead.
                </p>
                <x-ui.button type="submit" icon="check">Save the sheet</x-ui.button>
            </div>
        @endunless
    </form>
@endsection
