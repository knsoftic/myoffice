@extends('layouts.admin')

@section('title', $assignment->title)

@section('header')
    <x-ui.page-header :title="$assignment->title"
                      :subtitle="($assignment->course?->name ?? '').' · '.($assignment->batch?->code ?? '')"
                      icon="clipboard-document">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="clipboard-document-check"
                         :href="route('admin.assignment-submissions.index', $assignment)">Marking</x-ui.button>
            @if ($canDownloadBrief && $assignment->hasBrief())
                <x-ui.button variant="ghost" icon="arrow-down-tray"
                             :href="route('admin.assignments.brief.download', $assignment)">Brief</x-ui.button>
            @endif
            @if ($canPrint)
                <x-ui.button variant="ghost" icon="printer"
                             :href="route('admin.assignments.print', $assignment)" target="_blank">Print</x-ui.button>
            @endif
            @if ($canEdit)
                <x-ui.button variant="ghost" icon="pencil"
                             :href="route('admin.assignments.edit', $assignment)">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card label="Expected" :value="app_number($stats->expected)" icon="users" />
        <x-ui.stat-card label="Handed in" :value="app_number($stats->submitted)" icon="inbox-arrow-down" color="sky" />
        <x-ui.stat-card label="Late" :value="app_number($stats->late)" icon="clock" color="amber" />
        <x-ui.stat-card label="Marked" :value="app_number($stats->graded)" icon="check-badge" color="emerald" />
        <x-ui.stat-card label="Missed" :value="app_number($stats->missed)" icon="x-circle" color="rose" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$assignment->status->color()">{{ $assignment->status->label() }}</x-ui.badge>
                    <x-ui.badge :color="$assignment->submission_type->color()" size="xs">{{ $assignment->submission_type->label() }}</x-ui.badge>
                    @if ($hasGradedWork)
                        <x-ui.badge color="slate" size="xs">Marks given — the total, deadline and hand-in type are locked</x-ui.badge>
                    @endif
                </div>

                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ $assignment->status->description() }}</p>

                @if ($assignment->description)
                    <div class="mt-4 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $assignment->description }}</div>
                @endif

                @if ($assignment->instructions)
                    <div class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Instructions</div>
                        <div class="mt-1 whitespace-pre-line">{{ $assignment->instructions }}</div>
                    </div>
                @endif

                <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Deadline</dt>
                        <dd class="text-sm text-slate-700 dark:text-slate-200">{{ app_datetime($assignment->deadline_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Late work</dt>
                        <dd class="text-sm text-slate-700 dark:text-slate-200">
                            @if (! $assignment->late_submission_allowed)
                                Not accepted
                            @elseif ($assignment->late_cutoff_at)
                                Until {{ app_datetime($assignment->late_cutoff_at) }}
                            @else
                                Accepted, no cutoff
                            @endif
                            @if (bccomp((string) $assignment->late_penalty_percentage, '0.0000', 4) > 0)
                                <div class="text-xs text-slate-400">{{ app_number($assignment->late_penalty_percentage) }}% penalty, charged once</div>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Out of</dt>
                        <dd class="text-sm text-slate-700 dark:text-slate-200">
                            {{ app_number($assignment->total_marks) }}
                            @if ($assignment->passing_marks)
                                <span class="text-xs text-slate-400">· pass at {{ app_number($assignment->passing_marks) }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Attempts</dt>
                        <dd class="text-sm text-slate-700 dark:text-slate-200">
                            {{ app_number($assignment->max_attempts) }} ·
                            {{ app_number($assignment->max_files) }} file{{ $assignment->max_files === 1 ? '' : 's' }} each
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        <div class="grid gap-6">
            @if ($canPublish)
                <x-ui.card>
                    <x-ui.section-heading title="Status" />

                    <form method="POST" action="{{ route('admin.assignments.status', $assignment) }}" class="grid gap-3">
                        @csrf

                        <x-ui.form.select name="status" label="Move to">
                            <option value="published" @selected($assignment->status->value === 'published')>Published</option>
                            <option value="closed" @selected($assignment->status->value === 'closed')>Closed</option>
                            <option value="archived" @selected($assignment->status->value === 'archived')>Archived</option>
                        </x-ui.form.select>

                        <x-ui.form.input name="reason" label="Reason"
                                         help="Needed to reopen or archive. Closing records the missed submissions automatically." />

                        <x-ui.button type="submit" variant="secondary" icon="arrow-path">Apply</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card>
                <x-ui.section-heading title="The numbers" />

                <dl class="grid gap-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Still to mark</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($stats->ungraded()) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Still to hand in</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($stats->outstanding()) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Hand-in rate</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $stats->submissionRate() ? app_number($stats->submissionRate()).'%' : '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Average</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $stats->averageMarks ? app_number($stats->averageMarks) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Highest</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $stats->highestMarks ? app_number($stats->highestMarks) : '—' }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($canDelete)
                <x-ui.card>
                    <x-ui.section-heading title="Remove" />
                    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                        Nobody has submitted to this, so it can be removed. Once work has been handed in, an
                        assignment is closed rather than deleted.
                    </p>
                    <form method="POST" action="{{ route('admin.assignments.destroy', $assignment) }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" icon="trash">Remove assignment</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
