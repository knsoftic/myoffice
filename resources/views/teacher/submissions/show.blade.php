@extends('layouts.panel')

@section('title', 'Submission — '.($submission->student?->name ?? ''))

@section('header')
    <x-ui.page-header :title="$submission->student?->name ?? 'Submission'"
                      :subtitle="$submission->assignment?->title"
                      icon="clipboard-document-check">
        <x-slot:actions>
            <x-ui.button variant="ghost"
                         :href="route('teacher.submissions.index', $submission->assignment_id)">Back to marking</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$submission->status->color()">{{ $submission->status->label() }}</x-ui.badge>
                    <x-ui.badge color="slate" size="xs">Attempt {{ app_number($submission->attempt_no) }}</x-ui.badge>
                    @if ($submission->is_late)
                        <x-ui.badge color="amber" size="xs">
                            Late{{ $submission->minutes_late ? ' by '.app_number($submission->minutes_late).' minutes' : '' }}
                        </x-ui.badge>
                    @endif
                </div>

                @if ($submission->submission_text)
                    <div class="mt-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Their answer</div>
                        {{-- Rendered as text, never as HTML: a submission is a student's words. --}}
                        <div class="mt-2 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $submission->submission_text }}</div>
                    </div>
                @endif

                @if ($submission->files->isNotEmpty())
                    <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($submission->files as $file)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm text-slate-700 dark:text-slate-200">{{ $file->original_name }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ strtoupper((string) $file->extension) }} ·
                                        {{ app_number($file->file_size_bytes / 1024, 0) }} KB
                                    </div>
                                </div>
                                <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray"
                                             :href="route('teacher.submissions.file', [$submission, $file])">Open</x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if ($attempts->count() > 1)
                <x-ui.card>
                    <x-ui.section-heading title="Every attempt"
                                          subtitle="A resubmission supersedes its predecessor. Nothing is overwritten." />
                    <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($attempts as $attempt)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('teacher.submissions.show', $attempt) }}"
                                   class="text-sm text-slate-700 hover:underline dark:text-slate-200">
                                    Attempt {{ app_number($attempt->attempt_no) }}
                                </a>
                                <x-ui.badge :color="$attempt->status->color()" size="xs">{{ $attempt->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="grid gap-6">
            @if ($canGrade)
                <x-ui.card>
                    <x-ui.section-heading title="{{ $submission->marks_released_at ? 'Amend the mark' : 'Mark it' }}" />

                    <form method="POST" action="{{ route('teacher.submissions.grade', $submission) }}"
                          enctype="multipart/form-data" class="grid gap-3">
                        @csrf

                        <x-ui.form.input name="obtained_marks" label="Mark" type="number" step="0.01" min="0" required
                                         :value="old('obtained_marks', $submission->obtained_marks)"
                                         :suffix="'/ '.app_number($submission->total_marks)" />

                        <x-ui.form.textarea name="feedback" label="Feedback" rows="4"
                                            :value="old('feedback', $submission->feedback)" />

                        <x-ui.form.file name="feedback_file" label="Feedback file"
                                        :current="$submission->feedback_file_original_name"
                                        :hint="App\DataObjects\Files\FileRules::feedback()->describe()" />

                        @if ($submission->marks_released_at)
                            <x-ui.form.input name="reason" label="Why is it changing?" required
                                             help="This student has already seen their mark." />
                        @endif

                        <x-ui.button type="submit" icon="check">
                            {{ $submission->marks_released_at ? 'Amend' : 'Save the mark' }}
                        </x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
