@extends('layouts.admin')

@section('title', $task->reference . ' — ' . $task->title)

{{--
    Task detail — admin.tasks.show (phase-06 §8.7).

    Reading a task is wider than working on it: $canWrite is TaskPolicy::update(), which wants the
    assignee or somebody who manages the project, so a colleague's card reads without offering controls
    that would 403 on submit.
--}}
@php
    $user = auth()->user();
    $canWrite = (bool) ($canWrite ?? false);
    $canAssign = (bool) $user?->can('assign', $task);
@endphp

@section('header')
    <x-ui.page-header :title="$task->title" :subtitle="$task->reference . ' · ' . ($task->project?->code ?? '')" icon="check-circle">
        <x-slot:actions>
            @if ($task->project)
                <x-ui.button variant="secondary" :href="route('admin.projects.show', $task->project)">Project</x-ui.button>
                <x-ui.button variant="secondary" icon="view-columns" :href="route('admin.projects.board', $task->project)">Board</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Details">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt><dd><x-ui.badge :color="$task->status->color()" size="xs">{{ $task->status->label() }}</x-ui.badge></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Priority</dt><dd><x-ui.badge :color="$task->priority->color()" size="xs" variant="outline">{{ $task->priority->label() }}</x-ui.badge></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Assignee</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $task->assignee?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Reporter</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $task->reporter?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Milestone</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $task->milestone?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Due</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $task->due_date ? app_date($task->due_date) : '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Estimate</dt><dd class="text-sm tabular-nums text-slate-900 dark:text-white">{{ $task->estimated_hours ? app_number((float) $task->estimated_hours, 2) . ' h' : '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Logged</dt><dd class="text-sm tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $task->actual_hours, 2) }} h</dd></div>
                </dl>

                @if (filled($task->blocked_reason))
                    <p class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">Blocked: {{ $task->blocked_reason }}</p>
                @endif

                @if (filled($task->description))
                    <p class="mt-4 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $task->description }}</p>
                @endif
            </x-ui.card>

            <x-ui.card :title="'Checklist (' . $task->checklist_done . '/' . $task->checklist_total . ')'">
                @if ($task->checklistItems->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">No checklist lines yet.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($task->checklistItems as $item)
                            <li class="flex items-center gap-3">
                                @if ($canWrite)
                                    <form method="POST" action="{{ route('admin.checklist.update', $item) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="is_done" value="{{ $item->is_done ? 0 : 1 }}">
                                        <button type="submit" class="flex h-5 w-5 items-center justify-center rounded border border-slate-300 text-xs dark:border-slate-600" aria-label="Toggle">{{ $item->is_done ? '✓' : '' }}</button>
                                    </form>
                                @else
                                    <span class="flex h-5 w-5 items-center justify-center rounded border border-slate-300 text-xs dark:border-slate-600">{{ $item->is_done ? '✓' : '' }}</span>
                                @endif
                                <span @class(['text-sm', 'line-through text-slate-400 dark:text-slate-500' => $item->is_done, 'text-slate-700 dark:text-slate-200' => ! $item->is_done])>{{ $item->title }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('admin.checklist.store', $task) }}" class="mt-4 flex gap-2">
                        @csrf
                        <input type="text" name="title" maxlength="255" required placeholder="Add a line…" class="block w-full rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                        <x-ui.button type="submit" size="sm" icon="plus">Add</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($canWrite && $statuses !== [])
                <x-ui.card title="Move status">
                    <form method="POST" action="{{ route('admin.tasks.status', $task) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="status" :options="$statuses" placeholder="Choose a status" required aria-label="New status" />
                        <x-ui.form.input name="reason" label="Reason" help="Required when blocking, cancelling or reopening." maxlength="255" />
                        <x-ui.button type="submit" class="w-full" icon="arrow-right">Apply</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canAssign)
                <x-ui.card title="Assign">
                    <form method="POST" action="{{ route('admin.tasks.assign', $task) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="assigned_user_id" :options="$members" :selected="$task->assigned_user_id" placeholder="Unassigned" aria-label="Assignee" help="Only active members of this project are listed." />
                        <x-ui.button type="submit" class="w-full" variant="secondary" icon="user">Save</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($task->subtasks->isNotEmpty())
                <x-ui.card :title="'Subtasks (' . $task->subtask_done . '/' . $task->subtask_total . ')'">
                    <ul class="space-y-2">
                        @foreach ($task->subtasks as $subtask)
                            <li class="flex items-center justify-between gap-2">
                                <a href="{{ route('admin.tasks.show', $subtask) }}" class="truncate text-sm text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $subtask->title }}</a>
                                <x-ui.badge :color="$subtask->status->color()" size="xs">{{ $subtask->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
