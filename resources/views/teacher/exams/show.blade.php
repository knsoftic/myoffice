@extends('layouts.panel')

@section('title', $exam->name)

@section('header')
    <x-ui.page-header :title="$exam->name" icon="document-chart-bar"
                      :subtitle="$exam->exam_type->label().' · '.($exam->batch?->code ?? '').' · '.app_date($exam->scheduled_date)">
        <x-slot:actions>
            @if ($canMark)
                <x-ui.button icon="pencil-square" :href="route('teacher.results.sheet', $exam)">Enter marks</x-ui.button>
            @endif
            <x-ui.button variant="ghost" :href="route('teacher.exams.index')">All exams</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2">
            <x-ui.section-heading title="The paper" />

            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Status</dt>
                    <dd class="mt-1"><x-ui.badge :color="$exam->status->color()" :dot="true">{{ $exam->status->label() }}</x-ui.badge></dd>
                    <p class="mt-1 text-xs text-slate-400">{{ $exam->status->description() }}</p>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Out of</dt>
                    <dd class="mt-1 text-sm tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($exam->total_marks, 2) }}</dd>
                    <p class="mt-1 text-xs text-slate-400">pass at {{ app_number($exam->passing_marks, 2) }}</p>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Grade scale</dt>
                    <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $exam->scale?->code ?? "the institute's default" }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">When</dt>
                    <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                        {{ app_date($exam->scheduled_date) }}
                        @if ($exam->start_time)
                            <span class="text-slate-400">{{ app_time($exam->start_time) }}@if ($exam->end_time) – {{ app_time($exam->end_time) }}@endif</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Where</dt>
                    <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                        {{ $exam->delivery_mode->label() }}
                        @if ($exam->classroom)
                            <span class="text-slate-400">· {{ $exam->classroom->name ?? $exam->classroom->code }}</span>
                        @endif
                    </dd>
                </div>
                @if ($exam->topic)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Assesses</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $exam->topic->title }}</dd>
                    </div>
                @endif
            </dl>

            @if ($exam->instructions)
                <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-700/60">
                    <h4 class="text-xs uppercase tracking-wide text-slate-400">Instructions given to the class</h4>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $exam->instructions }}</p>
                </div>
            @endif

            @if ($exam->cancellation_reason)
                <p class="mt-6 rounded-lg bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    <span class="font-medium">Called off:</span> {{ $exam->cancellation_reason }}
                </p>
            @endif
        </x-ui.card>

        <x-ui.card>
            <x-ui.section-heading title="How the class did" />

            @if ($exam->results_entered_count > 0)
                <dl class="grid gap-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-400">On the roster</dt><dd class="tabular-nums">{{ app_number($exam->expected_count) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Sat it</dt><dd class="tabular-nums">{{ app_number($exam->appeared_count) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Absent</dt><dd class="tabular-nums">{{ app_number($exam->absent_count) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Passed</dt><dd class="tabular-nums">{{ app_number($exam->passed_count) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Average</dt><dd class="tabular-nums">{{ $exam->average_percentage === null ? '—' : app_number($exam->average_percentage, 2).'%' }}</dd></div>
                </dl>

                <p class="mt-4 text-xs text-slate-400">
                    Marks you enter are checked by somebody else before the class sees them — that is deliberate,
                    and it is why there is no publish button here.
                </p>
            @else
                <x-ui.empty-state icon="pencil-square" title="Nothing entered yet"
                                  description="The sheet opens once the exam has been marked as conducted." />
            @endif
        </x-ui.card>
    </div>
@endsection
