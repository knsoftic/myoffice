@extends('layouts.admin')

@section('title', ($mine ?? false) ? 'My tasks' : 'Tasks')

{{--
    Tasks list — admin.tasks.index / admin.tasks.my (phase-06 §7.3).

    Scoped by `Task::visibleTo()` in the controller (§9): without `tasks.view_any` this is the signed-in
    user's own and reported work plus anything inside a project their membership manages.
--}}
@php
    $user = auth()->user();
    $mine = $mine ?? false;
    $canCreate = (bool) $user?->can('tasks.create');
@endphp

@section('header')
    <x-ui.page-header
        :title="$mine ? 'My tasks' : 'Tasks'"
        :subtitle="$mine ? 'Everything assigned to you, across every project.' : 'Every task you can see.'"
        icon="check-circle">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="view-columns" :href="route('admin.tasks.board')">Board</x-ui.button>
            @if (! $mine)
                <x-ui.button variant="secondary" :href="route('admin.tasks.my')">My tasks</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.tasks.create')">New task</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.filter-bar placeholder="Search a task title…" :reset="route($mine ? 'admin.tasks.my' : 'admin.tasks.index')">
            <x-ui.form.select name="status" :options="$statuses" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="priority" :options="$priorities" :selected="request('priority')" placeholder="Any priority" size="sm" aria-label="Filter by priority" />
            <x-ui.form.select name="project_id" :options="$projects" :selected="request('project_id')" placeholder="Any project" size="sm" aria-label="Filter by project" />
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$tasks->isEmpty()" :columns="6">
            <x-slot:head>
                <th scope="col" class="px-4 py-3">Task</th>
                <th scope="col" class="px-4 py-3">Project</th>
                <th scope="col" class="px-4 py-3">Assignee</th>
                <th scope="col" class="px-4 py-3">Priority</th>
                <th scope="col" class="px-4 py-3">Due</th>
                <th scope="col" class="px-4 py-3">Status</th>
            </x-slot:head>

            @foreach ($tasks as $task)
                <tr>
                    <td class="min-w-[16rem]">
                        <a href="{{ route('admin.tasks.show', $task) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $task->title }}</a>
                        @if ($task->milestone)
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $task->milestone->name }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-sm">{{ $task->project?->code }}</td>
                    <td class="whitespace-nowrap text-sm">{{ $task->assignee?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap"><x-ui.badge :color="$task->priority->color()" size="xs" variant="outline">{{ $task->priority->label() }}</x-ui.badge></td>
                    <td class="whitespace-nowrap text-sm">{{ $task->due_date ? app_date($task->due_date) : '—' }}</td>
                    <td class="whitespace-nowrap"><x-ui.badge :color="$task->status->color()" size="xs">{{ $task->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="check-circle" title="No tasks" description="Tasks are the unit of work inside a project — they carry the estimate, the assignee and the hours.">
                    @if ($canCreate)
                        <x-ui.button icon="plus" :href="route('admin.tasks.create')">New task</x-ui.button>
                    @endif
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$tasks" label="tasks" />
    </div>
@endsection
