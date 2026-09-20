@extends('layouts.panel')

@section('title', 'My Projects')

{{--
    Client panel projects — client.projects.index (phase-06 §7.7, §8.11, §9's Client row).

    Nothing money-shaped or effort-shaped is on this page, and not because the template hides it:
    ProjectsSection::COLUMNS never selects budget, contract value, discount, net value, any commission
    column, the referral, the estimate or the hours. What is not fetched cannot leak.

    Variables (Client\ProjectController@index → ServesClientPortal::sectionList('projects')):
      $client, $section, $items (LengthAwarePaginator<Project>), $filters, $clientName, $portalSections
--}}

@php
    $projects = $items ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
    $statusOptions = enum_exists(\App\Enums\ProjectStatus::class) ? \App\Enums\ProjectStatus::options() : [];
@endphp

@section('header')
    @include('client.partials.header', [
        'client' => $client,
        'title' => 'My Projects',
        'subtitle' => 'Everything we are building for you, and how far along it is.',
        'icon' => 'folder',
    ])
@endsection

@section('content')
    <div class="space-y-4">
        <x-ui.filter-bar placeholder="Search a project…" :reset="route('client.projects.index')">
            <x-ui.form.select name="status" :options="$statusOptions" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
        </x-ui.filter-bar>

        @if ($projects->isEmpty())
            <x-ui.empty-state icon="folder" title="No projects yet" description="When we start work for you it will appear here, with its progress and milestones." />
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($projects as $project)
                    @php $percent = (float) $project->progress_percent; @endphp
                    <x-ui.card hover>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('client.projects.show', $project->getKey()) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $project->name }}</a>
                                <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->code }}</span>
                            </div>
                            <x-ui.badge :color="$project->status->color()" size="xs">{{ $project->status->label() }}</x-ui.badge>
                        </div>

                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                <span>Progress</span>
                                <span class="tabular-nums">{{ app_number($percent, 0) }}%</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, $percent)) }}%"></div>
                            </div>
                        </div>

                        @if ($project->deadline)
                            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Due {{ app_date($project->deadline) }}</p>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>

            <x-ui.pagination-summary :paginator="$projects" label="projects" />
        @endif
    </div>
@endsection
