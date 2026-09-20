@extends('layouts.admin')

@section('title', $milestone->name)

@php $user = auth()->user(); @endphp

@section('header')
    <x-ui.page-header :title="$milestone->name" :subtitle="$milestone->project?->code" icon="flag">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.milestones.index', $milestone->project)">All milestones</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.stat-card label="Progress" :value="app_number((float) $milestone->progress_percent, 0) . '%'" icon="chart-bar" />
                <x-ui.stat-card label="Status" :value="$milestone->status->label()" icon="flag" :color="$milestone->status->color()" />
                @if ($showMoney)
                    <x-ui.stat-card label="Payment value" :value="$milestone->amount === null ? 'Not billed' : money((string) $milestone->amount)" icon="banknotes" />
                @else
                    <x-ui.stat-card label="Tasks" :value="(string) $milestone->tasks->count()" icon="check-circle" />
                @endif
            </div>

            <x-ui.card title="Tasks">
                @if ($milestone->tasks->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">No tasks on this milestone.</p>
                @else
                    <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($milestone->tasks as $task)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('admin.tasks.show', $task) }}" class="truncate text-sm text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $task->title }}</a>
                                <x-ui.badge :color="$task->status->color()" size="xs">{{ $task->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        @if ($user?->can('changeStatus', $milestone) && $statuses !== [])
            <x-ui.card title="Move status">
                <form method="POST" action="{{ route('admin.milestones.status', $milestone) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.select name="status" :options="$statuses" placeholder="Choose a status" required aria-label="New status" />
                    <x-ui.form.input name="reason" label="Reason" help="Required when holding, cancelling or reopening." maxlength="255" />
                    <x-ui.button type="submit" class="w-full" icon="arrow-right">Apply</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
