@extends('layouts.panel')

@section('title', 'Exams')

@section('header')
    <x-ui.page-header title="Exams"
                      subtitle="Every paper your batches are sitting — whether or not you were named the examiner."
                      icon="document-chart-bar" />
@endsection

@section('content')
    @if ($awaitingMarks > 0)
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex gap-3">
                    <x-ui.icon name="pencil-square" class="h-5 w-5 shrink-0 text-amber-500" />
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ app_number($awaitingMarks) }}</span>
                        {{ $awaitingMarks === 1 ? 'paper is' : 'papers are' }} waiting for marks.
                    </p>
                </div>
                <x-ui.button size="sm" :href="route('teacher.results.index')">Go and mark</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="when" label="When" placeholder="Still to come">
                <option value="past" @selected(request('when') === 'past')>Already sat</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('teacher.exams.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="grid gap-3">
        @forelse ($exams as $exam)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('teacher.exams.show', $exam) }}"
                           class="text-base font-medium text-slate-800 hover:underline dark:text-slate-100">{{ $exam->name }}</a>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                            <span>{{ $exam->batch?->code }}</span>
                            <span>· {{ $exam->course?->name }}</span>
                            <span>· out of {{ app_number($exam->total_marks) }}</span>
                        </div>
                        <div class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ app_date($exam->scheduled_date) }}
                            @if ($exam->start_time)
                                <span class="text-slate-400">{{ app_time($exam->start_time) }}@if ($exam->end_time) – {{ app_time($exam->end_time) }}@endif</span>
                            @endif
                            @if ($exam->classroom)
                                <span class="text-slate-400">· {{ $exam->classroom->code }}</span>
                            @endif
                        </div>
                    </div>

                    <x-ui.badge :color="$exam->status->color()" size="xs">{{ $exam->status->label() }}</x-ui.badge>
                </div>
            </x-ui.card>
        @empty
            <x-ui.empty-state icon="document-chart-bar" title="No exams"
                              description="Papers appear here for every batch you teach, take a session for, or cover." />
        @endforelse
    </div>

    <x-ui.pagination-summary :paginator="$exams" label="exams" />
@endsection
