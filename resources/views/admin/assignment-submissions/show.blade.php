@extends('layouts.admin')

@section('title', 'Submission — '.($submission->student?->name ?? ''))

@section('header')
    <x-ui.page-header :title="$submission->student?->name ?? 'Submission'"
                      :subtitle="$submission->assignment?->title"
                      icon="clipboard-document-check">
        <x-slot:actions>
            <x-ui.button variant="ghost"
                         :href="route('admin.assignment-submissions.index', $submission->assignment_id)">Back to marking</x-ui.button>
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
                    @unless ($submission->isLive())
                        <x-ui.badge color="slate" size="xs">Replaced by a later attempt</x-ui.badge>
                    @endunless
                </div>

                @if ($submission->submission_text)
                    <div class="mt-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Their answer</div>
                        {{-- Rendered as text, never as HTML: a submission is a student's words, not markup. --}}
                        <div class="mt-2 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $submission->submission_text }}</div>
                    </div>
                @endif

                @if ($submission->files->isNotEmpty())
                    <div class="mt-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Files</div>
                        <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-700">
                            @foreach ($submission->files as $file)
                                <li class="flex items-center justify-between gap-3 py-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm text-slate-700 dark:text-slate-200">{{ $file->original_name }}</div>
                                        <div class="text-xs text-slate-400">
                                            {{ strtoupper((string) $file->extension) }} ·
                                            {{ app_number($file->file_size_bytes / 1024, 0) }} KB
                                            @if ($file->checksum_sha256 && in_array($file->checksum_sha256, $duplicateChecksums, true))
                                                · <span class="text-amber-500">an identical file was handed in by somebody else</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($canDownload)
                                        <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray"
                                                     :href="route('admin.assignment-submissions.file.download', [$submission, $file])">Open</x-ui.button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        @if ($duplicateChecksums !== [])
                            <x-ui.form.help class="mt-3">
                                A matching file is not an accusation. Two students handing in the same provided
                                template is the ordinary case — this is here so you can look, not so the system
                                can decide.
                            </x-ui.form.help>
                        @endif
                    </div>
                @endif
            </x-ui.card>

            @if ($attempts->count() > 1)
                <x-ui.card>
                    <x-ui.section-heading title="Every attempt"
                                          subtitle="A resubmission supersedes its predecessor. Nothing is overwritten and nothing is lost." />

                    <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($attempts as $attempt)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div>
                                    <a href="{{ route('admin.assignment-submissions.show', $attempt) }}"
                                       class="text-sm text-slate-700 hover:underline dark:text-slate-200">
                                        Attempt {{ app_number($attempt->attempt_no) }}
                                    </a>
                                    <div class="text-xs text-slate-400">
                                        {{ $attempt->submitted_at ? app_datetime($attempt->submitted_at) : 'not handed in' }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <x-ui.badge :color="$attempt->status->color()" size="xs">{{ $attempt->status->label() }}</x-ui.badge>
                                    @if ($attempt->final_marks !== null)
                                        <div class="text-xs tabular-nums text-slate-400">{{ app_number($attempt->final_marks) }}</div>
                                    @endif
                                </div>
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

                    <form method="POST" action="{{ route('admin.assignment-submissions.grade', $submission) }}"
                          enctype="multipart/form-data" class="grid gap-3">
                        @csrf

                        <x-ui.form.input name="obtained_marks" label="Mark" type="number" step="0.01" min="0" required
                                         :value="old('obtained_marks', $submission->obtained_marks)"
                                         :suffix="'/ '.app_number($submission->total_marks)"
                                         help="Out of what this submission was set against, not what the assignment says today." />

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

                <x-ui.card>
                    <x-ui.section-heading title="Send it back" />

                    <form method="POST" action="{{ route('admin.assignment-submissions.return', $submission) }}" class="grid gap-3">
                        @csrf
                        <x-ui.form.textarea name="feedback" label="What needs reworking" rows="3" required />
                        <x-ui.button type="submit" variant="secondary" icon="arrow-uturn-left">Return for rework</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($submission->wasAmended())
                <x-ui.card>
                    <x-ui.section-heading title="This mark was amended" />
                    <dl class="grid gap-2 text-sm">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">When</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ app_datetime($submission->amended_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">By</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $submission->amender?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Reason</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $submission->amendment_reason }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
