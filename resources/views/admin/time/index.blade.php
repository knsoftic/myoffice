@extends('layouts.admin')

@section('title', 'Time tracking')

{{--
    Time tracking — admin.time.index (phase-06 §8.9).

    The running figure is computed in the browser from $serverNow and the open segment's start. Nothing
    ticking is ever stored (INV-P5), which is why a crashed tab or a double-clicked Stop cannot inflate
    anything.
--}}
@php
    $user = auth()->user();
    $canLog = (bool) $user?->can('time_tracking.create');
@endphp

@section('header')
    <x-ui.page-header
        title="Time tracking"
        :subtitle="$seesAll ? 'Every hour logged across the projects you can see.' : 'Your own hours.'"
        icon="clock" />
@endsection

@section('content')
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Today" :value="app_number($totals['today'] / 3600, 2) . ' h'" icon="clock" />
            <x-ui.stat-card label="This week" :value="app_number($totals['week'] / 3600, 2) . ' h'" icon="calendar-days" />
            <x-ui.stat-card label="Live timers" :value="(string) $live->count()" icon="play" :delta-label="$live->isEmpty() ? 'None running' : 'One runs, the rest are paused'" />
            <x-ui.stat-card label="Entries" :value="(string) $entries->total()" icon="list-bullet" />
        </div>

        @if ($live->isNotEmpty())
            <x-ui.card title="Live">
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($live as $entry)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900 dark:text-white">{{ $entry->task?->title ?? $entry->project?->name }}</span>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $entry->project?->code }} · started {{ app_time($entry->started_at) }}</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.badge :color="$entry->status->color()" size="xs">{{ $entry->status->label() }}</x-ui.badge>
                                @if ($entry->status->value === 'running')
                                    <form method="POST" action="{{ route('admin.timer.pause', $entry) }}">@csrf<x-ui.button type="submit" size="sm" variant="secondary" icon="pause">Pause</x-ui.button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.timer.resume', $entry) }}">@csrf<x-ui.button type="submit" size="sm" variant="secondary" icon="play">Resume</x-ui.button></form>
                                @endif
                                <form method="POST" action="{{ route('admin.timer.stop', $entry) }}">@csrf<x-ui.button type="submit" size="sm" icon="stop">Stop</x-ui.button></form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.table :is-empty="$entries->isEmpty()" :columns="6">
                    <x-slot:head>
                        <th scope="col" class="px-4 py-3">Date</th>
                        <th scope="col" class="px-4 py-3">Project</th>
                        <th scope="col" class="px-4 py-3">Task</th>
                        @if ($seesAll)
                            <th scope="col" class="px-4 py-3">Worker</th>
                        @endif
                        <th scope="col" class="px-4 py-3 text-right">Hours</th>
                        <th scope="col" class="px-4 py-3">Source</th>
                    </x-slot:head>

                    @foreach ($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ app_date($entry->work_date) }}</td>
                            <td class="whitespace-nowrap text-sm">{{ $entry->project?->code }}</td>
                            <td class="max-w-[14rem] truncate text-sm">{{ $entry->task?->title ?? '—' }}</td>
                            @if ($seesAll)
                                <td class="whitespace-nowrap text-sm">{{ $entry->worker?->name ?? '—' }}</td>
                            @endif
                            <td class="whitespace-nowrap text-right tabular-nums text-sm">{{ app_number((float) $entry->duration_hours, 2) }}</td>
                            <td class="whitespace-nowrap"><x-ui.badge :color="$entry->source->color()" size="xs" variant="outline">{{ $entry->source->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="clock" title="No hours logged" description="Start a timer, or key in time you have already worked." />
                    </x-slot:empty>
                </x-ui.table>

                <div class="mt-3">
                    <x-ui.pagination-summary :paginator="$entries" label="entries" />
                </div>
            </div>

            @if ($canLog)
                <div class="space-y-4">
                    <x-ui.card title="Start a timer">
                        <form method="POST" action="{{ route('admin.timer.start') }}" class="space-y-3">
                            @csrf
                            <x-ui.form.select name="project_id" label="Project" :options="$projects" placeholder="Choose a project" required />
                            <x-ui.form.input name="description" label="What are you working on?" maxlength="500" />
                            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="switch" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                Switch my running timer to this
                            </label>
                            <x-ui.button type="submit" class="w-full" icon="play">Start</x-ui.button>
                        </form>
                    </x-ui.card>

                    <x-ui.card title="Log time by hand">
                        <form method="POST" action="{{ route('admin.time.store') }}" class="space-y-3">
                            @csrf
                            <x-ui.form.select name="project_id" label="Project" :options="$projects" placeholder="Choose a project" required />
                            <x-ui.form.input type="datetime-local" name="started_at" label="From" required />
                            <x-ui.form.input type="datetime-local" name="ended_at" label="To" required />
                            <x-ui.form.input name="description" label="Description" maxlength="500" />
                            <x-ui.form.input name="manual_reason" label="Reason" help="Required for entries dated further back than the allowed window." maxlength="255" />
                            <x-ui.button type="submit" class="w-full" variant="secondary" icon="plus">Record</x-ui.button>
                        </form>
                    </x-ui.card>
                </div>
            @endif
        </div>
    </div>
@endsection
