@extends('layouts.panel')

@section('title', 'Exams')

@section('header')
    <x-ui.page-header title="Exams"
                      subtitle="What you are sitting, and what you have sat. An exam that was called off stays on the list, marked as such."
                      icon="document-chart-bar" />
@endsection

@section('content')
    <div class="mb-4 flex gap-2">
        <x-ui.button :variant="$when === 'upcoming' ? 'primary' : 'ghost'" size="sm"
                     :href="route('student.exams.index')">
            Still to come
            @if ($upcomingCount > 0)
                <span class="ml-1 opacity-70">({{ app_number($upcomingCount) }})</span>
            @endif
        </x-ui.button>
        <x-ui.button :variant="$when === 'past' ? 'primary' : 'ghost'" size="sm"
                     :href="route('student.exams.index', ['when' => 'past'])">Already sat</x-ui.button>
    </div>

    <div class="grid gap-3">
        @forelse ($exams as $exam)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('student.exams.show', $exam) }}"
                           class="text-base font-medium text-slate-800 hover:underline dark:text-slate-100">{{ $exam->name }}</a>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                            <span>{{ $exam->exam_type->label() }}</span>
                            <span>· {{ $exam->course?->name }}</span>
                            <span>· out of {{ app_number($exam->total_marks) }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <x-ui.icon name="calendar-days" class="h-4 w-4 text-slate-400" />
                            <span>{{ app_date($exam->scheduled_date) }}</span>
                            @if ($exam->start_time)
                                <span class="text-slate-400">{{ app_time($exam->start_time) }}@if ($exam->end_time) – {{ app_time($exam->end_time) }}@endif</span>
                            @endif
                            @if ($exam->classroom)
                                <span class="text-slate-400">· {{ $exam->classroom->name ?? $exam->classroom->code }}</span>
                            @endif
                        </div>
                    </div>

                    <x-ui.badge :color="$exam->status->color()" size="xs">{{ $exam->status->label() }}</x-ui.badge>
                </div>

                @if ($exam->cancellation_reason)
                    <p class="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        <span class="font-medium">Called off:</span> {{ $exam->cancellation_reason }}
                    </p>
                @endif
            </x-ui.card>
        @empty
            <x-ui.empty-state icon="document-chart-bar"
                              :title="$when === 'past' ? 'Nothing sat yet' : 'Nothing coming up'"
                              description="Exams appear here once your batch has been told about them." />
        @endforelse
    </div>

    <x-ui.pagination-summary :paginator="$exams" label="exams" />
@endsection
