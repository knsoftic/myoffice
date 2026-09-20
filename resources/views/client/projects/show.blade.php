@extends('layouts.panel')

@section('title', $record->name ?? 'Project')

{{--
    Client panel project detail — client.projects.show (phase-06 §7.7, §8.11).

    $record is whatever ProjectsSection::find() returned for *this* client; another client's id never
    gets here, because find() answers null and the controller turns that into a 404 rather than a 403
    that would confirm the project exists.
--}}

@php
    $project = $record;
    $percent = (float) $project->progress_percent;
    $milestones = $milestones ?? collect();
@endphp

@section('header')
    @include('client.partials.header', [
        'client' => $client,
        'title' => $project->name,
        'subtitle' => $project->code,
        'icon' => 'folder',
    ])
@endsection

@section('content')
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card label="Progress" :value="app_number($percent, 0) . '%'" icon="chart-bar" />
            <x-ui.stat-card label="Status" :value="$project->status->label()" icon="flag" :color="$project->status->color()" />
            <x-ui.stat-card label="Deadline" :value="$project->deadline ? app_date($project->deadline) : 'Not set'" icon="calendar-days" />
        </div>

        @if (filled($project->description))
            <x-ui.card title="About this project">
                <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $project->description }}</p>
            </x-ui.card>
        @endif

        <x-ui.card title="Where we are">
            <div class="h-3 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ min(100, max(0, $percent)) }}%"></div>
            </div>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ app_number($percent, 0) }}% complete{{ $project->start_date ? ', started ' . app_date($project->start_date) : '' }}.</p>

            <x-slot:footer>
                <a href="{{ route('client.projects.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">Back to my projects</a>
            </x-slot:footer>
        </x-ui.card>
    </div>
@endsection
