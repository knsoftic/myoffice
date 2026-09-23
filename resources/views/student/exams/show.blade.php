@extends('layouts.panel')

@section('title', $exam->name)

@section('header')
    <x-ui.page-header :title="$exam->name" icon="document-chart-bar"
                      :subtitle="$exam->exam_type->label().' · '.($exam->course?->name ?? '')">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('student.exams.index')">All exams</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">When</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                            {{ app_date($exam->scheduled_date) }}
                            @if ($exam->start_time)
                                <div class="text-slate-400">{{ app_time($exam->start_time) }}@if ($exam->end_time) – {{ app_time($exam->end_time) }}@endif</div>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Where</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                            {{ $exam->delivery_mode->label() }}
                            @if ($exam->classroom)
                                <div class="text-slate-400">{{ $exam->classroom->name ?? $exam->classroom->code }}</div>
                            @endif
                        </dd>
                        @if ($exam->meeting_url)
                            <a href="{{ $exam->meeting_url }}" rel="noopener noreferrer" target="_blank"
                               class="mt-1 block truncate text-xs text-brand-600 hover:underline dark:text-brand-400">Join the exam</a>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Out of</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-700 dark:text-slate-200">
                            {{ app_number($exam->total_marks, 2) }}
                            <span class="text-slate-400">· pass at {{ app_number($exam->passing_marks, 2) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Status</dt>
                        <dd class="mt-1"><x-ui.badge :color="$exam->status->color()" :dot="true">{{ $exam->status->label() }}</x-ui.badge></dd>
                    </div>
                </dl>

                @if ($exam->instructions)
                    <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-700/60">
                        <h4 class="text-xs uppercase tracking-wide text-slate-400">What to know beforehand</h4>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $exam->instructions }}</p>
                    </div>
                @endif

                @if ($exam->cancellation_reason)
                    <p class="mt-6 rounded-lg bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        <span class="font-medium">This exam was called off:</span> {{ $exam->cancellation_reason }}
                    </p>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card>
            <x-ui.section-heading title="Your result" />

            @if ($result)
                <div class="text-center">
                    <div class="text-4xl font-semibold tabular-nums text-slate-800 dark:text-slate-100">
                        {{ $result->obtained_marks === null ? '—' : app_number($result->obtained_marks, 2) }}
                    </div>
                    <div class="mt-1 text-sm text-slate-400">out of {{ app_number($result->total_marks, 2) }}</div>

                    @if ($result->grade)
                        <div class="mt-4">
                            <x-ui.badge :color="$result->band?->color ?? 'slate'" size="lg">{{ $result->grade }}</x-ui.badge>
                        </div>
                        <div class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ app_number($result->percentage, 2) }}%
                            @if ($result->position_in_batch)
                                · {{ app_ordinal($result->position_in_batch) }} in the batch
                            @endif
                        </div>
                    @endif

                    <div class="mt-4">
                        <x-ui.badge :color="$result->is_passed ? 'emerald' : 'rose'" size="sm">
                            {{ $result->is_passed ? 'Passed' : 'Not passed' }}
                        </x-ui.badge>
                    </div>
                </div>

                @if ($result->remarks)
                    <p class="mt-6 border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-slate-700/60 dark:text-slate-300">
                        {{ $result->remarks }}
                    </p>
                @endif

                <x-ui.button variant="ghost" size="sm" class="mt-4 w-full"
                             :href="route('student.results.show', $result)">See the full result</x-ui.button>
            @else
                <x-ui.empty-state icon="clock" title="Not out yet"
                                  description="Marks appear here once they have been entered, checked by a second person and published." />
            @endif
        </x-ui.card>
    </div>
@endsection
