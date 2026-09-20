@extends('layouts.panel')

@section('title', 'Tasks')

{{--
    Client panel tasks — client.tasks.index (phase-06 §7.7, §9, F-3.1).

    Two switches decide whether anything appears at all: the global projects.client_can_see_tasks and the
    per-row tasks.is_client_visible. No hours are here — TasksSection does not select them.
--}}

@php
    $tasks = $items ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
@endphp

@section('header')
    @include('client.partials.header', [
        'client' => $client,
        'title' => 'Tasks',
        'subtitle' => 'The work in progress on your projects.',
        'icon' => 'check-circle',
    ])
@endsection

@section('content')
    @if ($tasks->isEmpty())
        <x-ui.empty-state icon="check-circle" title="Nothing to show" description="Tasks appear here as we start work on them." />
    @else
        <div class="space-y-4">
            <x-ui.card>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($tasks as $task)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $task->title }}</span>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ $task->project?->name }}
                                    @if ($task->due_date) · due {{ app_date($task->due_date) }} @endif
                                </span>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-xs tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $task->progress_percent, 0) }}%</span>
                                <x-ui.badge :color="$task->status->color()" size="xs">{{ $task->status->label() }}</x-ui.badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.pagination-summary :paginator="$tasks" label="tasks" />
        </div>
    @endif
@endsection
