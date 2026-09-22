@extends('layouts.print')

@section('title', $assignment->title)

@section('document')
    <div class="text-sm">
        <div class="font-semibold">{{ $assignment->batch?->code }}</div>
        <div>Due {{ app_datetime($assignment->deadline_at) }}</div>
        <div>Out of {{ app_number($assignment->total_marks) }}</div>
    </div>
@endsection

@section('content')
    @php
        // Assembled here rather than as inline @if inside a sentence: a conditional that has to sit
        // mid-clause is how a Blade template ends up unparseable, and the sentence is easier to read
        // in one piece anyway.
        $lateness = ! $assignment->late_submission_allowed
            ? 'Late work is not accepted.'
            : 'Late work is accepted'
                .($assignment->late_cutoff_at ? ' until '.app_datetime($assignment->late_cutoff_at) : '')
                .(bccomp((string) $assignment->late_penalty_percentage, '0.0000', 4) > 0
                    ? ', with a '.app_number($assignment->late_penalty_percentage).'% penalty charged once.'
                    : '.');
    @endphp

    <article>
        <header class="border-b border-slate-300 pb-4">
            <h1 class="text-2xl font-semibold">{{ $assignment->title }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ $assignment->course?->name }}
                @if ($assignment->teacher)
                    · {{ $assignment->teacher->employee_id }}
                @endif
            </p>
            @if ($assignment->passing_marks)
                <p class="mt-2 text-sm"><strong>Pass mark:</strong> {{ app_number($assignment->passing_marks) }}</p>
            @endif
        </header>

        @if ($assignment->description)
            <section class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">The brief</h2>
                <div class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $assignment->description }}</div>
            </section>
        @endif

        @if ($assignment->instructions)
            <section class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Instructions</h2>
                <div class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $assignment->instructions }}</div>
            </section>
        @endif

        <section class="mt-6 text-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Handing in</h2>
            <p class="mt-2">{{ $assignment->submission_type->description() }}</p>
            <p class="mt-1">{{ $lateness }}</p>
        </section>
    </article>
@endsection
