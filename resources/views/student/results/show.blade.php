@extends('layouts.panel')

@section('title', 'Result — '.($result->exam?->name ?? ''))

@section('header')
    <x-ui.page-header :title="$result->exam?->name ?? 'Result'" icon="trophy"
                      :subtitle="($result->exam?->course?->name ?? '').' · '.app_date($result->exam?->scheduled_date)">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('student.results.index')">All results</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-1">
            <div class="text-center">
                <div class="text-5xl font-semibold tabular-nums text-slate-800 dark:text-slate-100">
                    {{ $result->obtained_marks === null ? '—' : app_number($result->obtained_marks, 2) }}
                </div>
                <div class="mt-1 text-sm text-slate-400">out of {{ app_number($result->total_marks, 2) }}</div>

                @if ($result->grade)
                    <div class="mt-5">
                        <x-ui.badge :color="$result->band?->color ?? 'slate'" size="lg">{{ $result->grade }}</x-ui.badge>
                    </div>
                    @if ($result->band?->title)
                        <div class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $result->band->title }}</div>
                    @endif
                @endif

                <div class="mt-5">
                    <x-ui.badge :color="$result->is_passed ? 'emerald' : 'rose'" size="sm">
                        {{ $result->is_passed ? 'Passed' : 'Not passed' }}
                    </x-ui.badge>
                </div>
            </div>
        </x-ui.card>

        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <x-ui.section-heading title="The detail" />

                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Percentage</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-700 dark:text-slate-200">
                            {{ $result->percentage === null ? '—' : app_number($result->percentage, 2).'%' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Attendance</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $result->attendance_status->label() }}</dd>
                        <p class="mt-1 text-xs text-slate-400">{{ $result->attendance_status->description() }}</p>
                    </div>
                    @if ($showPosition)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Position</dt>
                            <dd class="mt-1 text-sm tabular-nums text-slate-700 dark:text-slate-200">
                                {{ app_ordinal($result->position_in_batch) }}
                            </dd>
                            <p class="mt-1 text-xs text-slate-400">Equal marks share a position.</p>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Grade points</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-700 dark:text-slate-200">
                            {{ $result->grade_point === null ? '—' : app_number($result->grade_point, 2) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Graded on</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $result->scale?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Published</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ app_date($result->published_at) }}</dd>
                    </div>
                </dl>

                @if ($result->remarks)
                    <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-700/60">
                        <h4 class="text-xs uppercase tracking-wide text-slate-400">Remarks</h4>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $result->remarks }}</p>
                    </div>
                @endif

                @if ($result->wasAmended())
                    <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <span class="font-medium">This mark was corrected on {{ app_date($result->amended_at) }}.</span>
                        {{ $result->amendment_reason }}
                    </p>
                @endif
            </x-ui.card>

            @if ($result->exam)
                <x-ui.card>
                    <x-ui.section-heading title="The exam" />
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-slate-600 dark:text-slate-300">
                            {{ $result->exam->exam_type->label() }} · {{ $result->exam->batch?->code }}
                            <div class="text-xs text-slate-400">
                                pass at {{ app_number($result->exam->passing_marks, 2) }} out of {{ app_number($result->exam->total_marks, 2) }}
                            </div>
                        </div>
                        <x-ui.button variant="ghost" size="sm" :href="route('student.exams.show', $result->exam)">See the exam</x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
