@extends('layouts.panel')

@section('title', $assignment->title)

@section('header')
    <x-ui.page-header :title="$assignment->title"
                      :subtitle="$assignment->course?->name"
                      icon="clipboard-document">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('student.assignments.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$assignment->status->color()" size="xs">{{ $assignment->status->label() }}</x-ui.badge>
                    <x-ui.badge color="slate" size="xs">Out of {{ app_number($assignment->total_marks) }}</x-ui.badge>
                    @if ($assignment->passing_marks)
                        <x-ui.badge color="slate" size="xs">Pass at {{ app_number($assignment->passing_marks) }}</x-ui.badge>
                    @endif
                </div>

                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    Due {{ app_datetime($assignment->deadline_at) }}.
                    @if (! $assignment->late_submission_allowed)
                        Late work is not accepted.
                    @elseif ($assignment->late_cutoff_at)
                        Late work is accepted until {{ app_datetime($assignment->late_cutoff_at) }}.
                    @else
                        Late work is accepted.
                    @endif
                    @if (bccomp((string) $assignment->late_penalty_percentage, '0.0000', 4) > 0)
                        A late submission loses {{ app_number($assignment->late_penalty_percentage) }}% of the total, once.
                    @endif
                </p>

                @if ($assignment->description)
                    <div class="mt-4 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $assignment->description }}</div>
                @endif

                @if ($assignment->instructions)
                    <div class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">How to hand it in</div>
                        <div class="mt-1 whitespace-pre-line">{{ $assignment->instructions }}</div>
                    </div>
                @endif

                @if ($assignment->hasBrief())
                    <div class="mt-4">
                        <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray"
                                     :href="route('student.assignments.brief', $assignment)">Open the brief</x-ui.button>
                    </div>
                @endif
            </x-ui.card>

            @if ($canSubmit)
                <x-ui.card>
                    <x-ui.section-heading title="{{ $submission && ! $submission->isDraft() ? 'Hand in again' : 'Hand it in' }}"
                                          :subtitle="$assignment->submission_type->description()" />

                    @if (! $acceptsWork)
                        <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            This assignment is no longer accepting work.
                        </div>
                    @elseif ($submission && $submission->isDraft())
                        <form method="POST" action="{{ route('student.submissions.submit', $submission) }}"
                              enctype="multipart/form-data" class="grid gap-3">
                            @csrf
                            @include('student.assignments._fields')
                            <div class="flex gap-2">
                                <x-ui.button type="submit" icon="paper-airplane">Hand in</x-ui.button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('student.submissions.withdraw', $submission) }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">Discard this draft</x-ui.button>
                        </form>
                    @elseif ($submission)
                        @if ($assignment->allow_resubmission && $submission->attempt_no < $assignment->max_attempts)
                            <form method="POST" action="{{ route('student.submissions.resubmit', $assignment) }}"
                                  enctype="multipart/form-data" class="grid gap-3">
                                @csrf
                                @include('student.assignments._fields')
                                <x-ui.form.help>
                                    This is attempt {{ app_number($submission->attempt_no + 1) }} of
                                    {{ app_number($assignment->max_attempts) }}. Your previous attempt is kept, not replaced.
                                </x-ui.form.help>
                                <x-ui.button type="submit" icon="arrow-path">Hand in a new attempt</x-ui.button>
                            </form>
                        @else
                            <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                You have used all {{ app_number($assignment->max_attempts) }} attempts on this assignment.
                            </div>
                        @endif
                    @else
                        <form method="POST" action="{{ route('student.assignments.submission.start', $assignment) }}">
                            @csrf
                            <x-ui.button type="submit" icon="pencil-square">Start</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="grid gap-6">
            @if ($submission)
                <x-ui.card>
                    <x-ui.section-heading title="Your submission" />

                    <dl class="grid gap-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Status</dt>
                            <dd><x-ui.badge :color="$submission->status->color()" size="xs">{{ $submission->status->label() }}</x-ui.badge></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Attempt</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($submission->attempt_no) }}</dd>
                        </div>
                        @if ($submission->submitted_at)
                            <div class="flex justify-between">
                                <dt class="text-slate-500 dark:text-slate-400">Handed in</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ app_datetime($submission->submitted_at) }}</dd>
                            </div>
                        @endif
                        @if ($submission->is_late)
                            <div class="flex justify-between">
                                <dt class="text-slate-500 dark:text-slate-400">Late by</dt>
                                <dd class="text-amber-500">{{ app_number($submission->minutes_late) }} minutes</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($submission->files->isNotEmpty())
                        <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-slate-700">
                            @foreach ($submission->files as $file)
                                <li class="flex items-center justify-between gap-2 py-2">
                                    <span class="truncate text-slate-600 dark:text-slate-300">{{ $file->original_name }}</span>
                                    <a href="{{ route('student.submissions.file', [$submission, $file]) }}"
                                       class="text-xs text-slate-400 hover:underline">open</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                @if ($marksVisible)
                    <x-ui.card>
                        <x-ui.section-heading title="Your mark" />

                        <div class="text-center">
                            <div class="text-3xl font-semibold tabular-nums text-slate-800 dark:text-slate-100">
                                {{ app_number($submission->final_marks) }}
                                <span class="text-base text-slate-400">/ {{ app_number($submission->total_marks) }}</span>
                            </div>
                            @if ($submission->percentage !== null)
                                <div class="text-sm text-slate-400">{{ app_number($submission->percentage) }}%</div>
                            @endif
                            @if ($submission->is_passed !== null)
                                <x-ui.badge :color="$submission->is_passed ? 'emerald' : 'rose'" class="mt-2">
                                    {{ $submission->is_passed ? 'Passed' : 'Not passed' }}
                                </x-ui.badge>
                            @endif
                        </div>

                        @if (bccomp((string) $submission->penalty_marks, '0.00', 2) > 0)
                            <x-ui.form.help class="mt-3">
                                {{ app_number($submission->obtained_marks) }} was awarded, less
                                {{ app_number($submission->penalty_marks) }} for handing in late.
                            </x-ui.form.help>
                        @endif

                        @if ($submission->feedback)
                            <div class="mt-4 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $submission->feedback }}</div>
                        @endif

                        @if ($submission->feedback_file_path)
                            <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray" class="mt-3"
                                         :href="route('student.submissions.feedback', $submission)">Feedback file</x-ui.button>
                        @endif
                    </x-ui.card>
                @elseif ($submission->status->isGraded())
                    <x-ui.card>
                        <x-ui.empty-state icon="clock" title="Marked, not released"
                                          description="Your teacher is marking the whole class before releasing the results." />
                    </x-ui.card>
                @endif
            @endif

            @if ($attempts->count() > 1)
                <x-ui.card>
                    <x-ui.section-heading title="Your attempts" />
                    <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                        @foreach ($attempts as $attempt)
                            <li class="flex items-center justify-between gap-2 py-2">
                                <span class="text-slate-600 dark:text-slate-300">Attempt {{ app_number($attempt->attempt_no) }}</span>
                                <x-ui.badge :color="$attempt->status->color()" size="xs">{{ $attempt->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
