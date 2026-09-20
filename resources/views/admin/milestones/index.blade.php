@extends('layouts.admin')

@section('title', 'Milestones — ' . $project->code)

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('project_milestones.create');
@endphp

@section('header')
    <x-ui.page-header :title="'Milestones — ' . $project->name" :subtitle="$project->code" icon="flag">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.projects.show', $project)">Back to project</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card title="Milestones" subtitle="Weight decides how much each one counts toward the project percentage.">
                @if ($milestones->isEmpty())
                    <x-ui.empty-state icon="flag" title="No milestones" description="Break the project into the stages you deliver and bill against." />
                @else
                    <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($milestones as $milestone)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('admin.milestones.show', $milestone) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $milestone->name }}</a>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        Weight {{ app_number((float) $milestone->weight, 2) }}
                                        @if ($milestone->deadline) · due {{ app_date($milestone->deadline) }} @endif
                                        @if ($showMoney && $milestone->amount !== null) · {{ money((string) $milestone->amount) }} @endif
                                    </span>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="text-xs tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $milestone->progress_percent, 0) }}%</span>
                                    <x-ui.badge :color="$milestone->status->color()" size="xs">{{ $milestone->status->label() }}</x-ui.badge>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        @if ($canCreate)
            <x-ui.card title="Add a milestone">
                <form method="POST" action="{{ route('admin.milestones.store', $project) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input name="name" label="Name" required maxlength="200" :value="old('name')" />
                    <x-ui.form.input type="date" name="deadline" label="Deadline" :value="old('deadline')" help="A date after the project deadline is recorded with a warning, not refused." />
                    <x-ui.form.input type="number" step="0.0001" min="0.0001" name="weight" label="Weight" :value="old('weight', '1.0000')" required />
                    @if ($showMoney)
                        <x-ui.form.input type="number" step="0.01" min="0" name="amount" label="Payment value" :value="old('amount')" help="Leave empty when this milestone is not billed." />
                    @endif
                    <x-ui.button type="submit" class="w-full" icon="plus">Add milestone</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
