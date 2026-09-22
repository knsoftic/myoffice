@extends('layouts.panel')

@section('title', 'Assignments')

@section('header')
    <x-ui.page-header title="Assignments"
                      subtitle="What you have been set, what you have handed in, and what you were given for it."
                      icon="clipboard-document" />
@endsection

@section('content')
    <div class="grid gap-3">
        @forelse ($assignments as $assignment)
            @php($submission = $mine[$assignment->id] ?? null)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('student.assignments.show', $assignment) }}"
                           class="text-base font-medium text-slate-800 hover:underline dark:text-slate-100">{{ $assignment->title }}</a>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                            <span>{{ $assignment->course?->name }}</span>
                            <span>· due {{ app_datetime($assignment->deadline_at) }}</span>
                            <span>· out of {{ app_number($assignment->total_marks) }}</span>
                        </div>
                    </div>

                    <div class="text-right">
                        @if ($submission === null)
                            <x-ui.badge color="slate" size="xs">Not handed in</x-ui.badge>
                            @if ($assignment->deadline_at->isPast())
                                <div class="mt-1 text-xs text-rose-500">The deadline has passed</div>
                            @endif
                        @else
                            <x-ui.badge :color="$submission->status->color()" size="xs">{{ $submission->status->label() }}</x-ui.badge>
                            @if ($submission->marksVisibleToStudent())
                                <div class="mt-1 text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200">
                                    {{ app_number($submission->final_marks) }} / {{ app_number($submission->total_marks) }}
                                </div>
                            @elseif ($submission->is_late)
                                <div class="mt-1 text-xs text-amber-500">Handed in late</div>
                            @endif
                        @endif
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty-state icon="clipboard-document" title="Nothing set yet"
                                  description="Assignments appear here when your teacher publishes them." />
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.pagination-summary :paginator="$assignments" label="assignments" class="mt-4" />
@endsection
