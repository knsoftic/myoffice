@extends('layouts.admin')

@section('title', 'Marks — '.$exam->name)

@section('header')
    <x-ui.page-header :title="$exam->name" icon="pencil-square"
                      :subtitle="($exam->batch?->code ?? 'no batch').' · '.app_date($exam->scheduled_date).' · out of '.app_number($exam->total_marks, 2)">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-down-tray"
                         :href="route('admin.exam-results.export', ['exam' => $exam, 'format' => 'csv'])">Export</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.exams.show', $exam)">Back to the exam</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        The whole sheet is one form and one POST. There is no per-row save, deliberately: INV-20-6
        says a sheet is all-or-nothing, and a marker who can save one row at a time will leave a class
        half-entered. A refusal comes back against the student who caused it, with everything else
        still in the boxes.
    --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="On the roster" :value="app_number($rows->count())" color="slate" />
        <x-ui.stat-card label="Status" :value="$exam->status->label()" :color="$exam->status->color()" />
        <x-ui.stat-card label="Grade scale" :value="$scale->code" color="indigo" />
        <x-ui.stat-card label="Pass mark"
                        :value="app_number($exam->passing_marks, 2).' / '.app_number($exam->total_marks, 2)"
                        color="sky" />
    </div>

    @if ($readonly)
        <x-ui.card class="mb-4 border-slate-200 dark:border-slate-700">
            <div class="flex gap-3">
                <x-ui.icon name="lock-closed" class="h-5 w-5 shrink-0 text-slate-400" />
                <div class="text-sm text-slate-600 dark:text-slate-300">
                    <p class="font-medium text-slate-700 dark:text-slate-200">This sheet is closed.</p>
                    <p class="mt-1">
                        Marks can only be entered while an exam is conducted or being marked. This one is
                        {{ mb_strtolower($exam->status->label()) }} — a published result is corrected by amending the
                        row with a reason, not by re-entering the sheet.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endif

    <form method="POST" action="{{ route('admin.exam-results.save', $exam) }}">
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
                                <div class="mt-1 text-xs text-slate-400">
                                    {{ app_number($result->percentage, 2) }}%
                                    @if ($result->position_in_batch)
                                        · {{ app_ordinal($result->position_in_batch) }}
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-slate-400">on save</span>
                            @endif
                            @if ($result?->wasAmended())
                                <div class="mt-1 text-xs text-amber-500" title="{{ $result->amendment_reason }}">amended</div>
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
                                      description="The roster is taken as it stood on the exam's own date — not today's. A student who joined last week was never expected to sit it." />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>

        @unless ($readonly || $rows->isEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-400">
                    Saved together or not at all. An absence is not a zero — leave the marks box empty and set the
                    attendance instead.
                </p>
                <x-ui.button type="submit" icon="check">Save the sheet</x-ui.button>
            </div>
        @endunless
    </form>

    @if ($exam->results_entered_count > 0)
        <x-ui.card class="mt-6">
            <x-ui.section-heading title="Checking and publishing"
                                  description="Two decisions, taken by two people. Whoever entered a mark on this sheet cannot be the one who checks it." />

            <div class="flex flex-wrap items-center gap-3">
                @if ($exam->results_verified_at)
                    <x-ui.badge color="emerald" :dot="true">Checked {{ app_date($exam->results_verified_at) }}</x-ui.badge>
                @elseif ($canVerify)
                    <form method="POST" action="{{ route('admin.exam-results.verify', $exam) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="shield-check">Check this sheet</x-ui.button>
                    </form>
                @else
                    <p class="text-sm text-slate-400">Waiting on somebody with the right to check results.</p>
                @endif

                @if ($canPublish && ! $exam->isPublished() && $exam->results_verified_at)
                    <form method="POST" action="{{ route('admin.exam-results.publish', $exam) }}">
                        @csrf
                        <x-ui.button type="submit" icon="megaphone">Publish to the class</x-ui.button>
                    </form>
                @endif

                @if ($canPublish && $exam->isPublished())
                    <x-ui.button variant="ghost" icon="arrow-uturn-left"
                                 x-on:click="$dispatch('open-modal', 'unpublish-results')">Withdraw the results</x-ui.button>
                    <x-ui.button variant="ghost" icon="printer" :href="route('admin.result-cards.index', $exam)">Result cards</x-ui.button>
                @endif
            </div>
        </x-ui.card>

        <x-ui.modal name="unpublish-results" title="Withdraw these results?" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.exam-results.unpublish', $exam) }}">
                @csrf

                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    Students stop seeing their marks immediately and the sheet reopens for correction. Nothing is
                    deleted, and the reason goes on the record.
                </p>

                <x-ui.form.textarea name="reason" label="Why" rows="3" required
                                    help="Staff only. Say what went wrong, so the next person does not have to guess." />

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'unpublish-results')">Leave them up</x-ui.button>
                    <x-ui.button type="submit" variant="danger" icon="arrow-uturn-left">Withdraw</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
