@extends('layouts.admin')

@section('title', $project->code . ' — ' . $project->name)

{{--
    Project detail — admin.projects.show (phase-06 §8.3).

    $showMoney gates every money figure. The controller does not select those columns without
    `projects.view_financial`, so this file never has to blank one (§9).

    $derivedProgress is what §6.3 computes right now. On a manual project it is shown **beside** the
    override, so the gap a human introduced is always visible ([D-P6-3]).
--}}
@php
    $user = auth()->user();
    $percent = (float) $project->progress_percent;
    $manual = $project->progress_mode->value === 'manual';
    $canEdit = (bool) $user?->can('update', $project);
    $canStatus = (bool) $user?->can('changeStatus', $project);
@endphp

@section('header')
    <x-ui.page-header :title="$project->name" :subtitle="$project->code" icon="folder">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="view-columns" :href="route('admin.projects.board', $project)">Board</x-ui.button>
            @if ($showMoney)
                <x-ui.button variant="secondary" icon="banknotes" :href="route('admin.projects.value.index', $project)">Value</x-ui.button>
            @endif
            <x-ui.button variant="secondary" icon="user-group" :href="route('admin.projects.members.index', $project)">Team</x-ui.button>
            @if ($canEdit)
                <x-ui.button icon="pencil-square" :href="route('admin.projects.edit', $project)">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Status" :value="$project->status->label()" icon="flag" :color="$project->status->color()" />
            <x-ui.stat-card label="Progress" :value="app_number($percent, 0) . '%'" icon="chart-bar" :delta-label="$manual ? 'Manual override — derived figure is ' . app_number((float) $derivedProgress, 0) . '%' : 'Derived from the work below'" />
            <x-ui.stat-card label="Logged" :value="app_number((float) $project->actual_hours, 2) . ' h'" icon="clock" />
            @if ($showMoney)
                <x-ui.stat-card label="Net value" :value="money((string) $project->net_value)" icon="banknotes" :delta-label="$project->value_revision_count . ' revision(s)'" />
            @else
                <x-ui.stat-card label="Tasks" :value="(string) ($taskCounts['total'] ?? 0)" icon="check-circle" />
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2" title="Details">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Client</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $project->client?->company_name ?: $project->client?->name ?: '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Manager</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $project->projectManager?->name ?: 'Unassigned' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Type</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $project->project_type->label() }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Priority</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $project->priority->label() }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Start</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $project->start_date ? app_date($project->start_date) : '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Deadline</dt><dd class="text-sm text-slate-900 dark:text-white">{{ $project->deadline ? app_date($project->deadline) : '—' }}</dd></div>
                </dl>

                @if (filled($project->description))
                    <p class="mt-4 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $project->description }}</p>
                @endif
            </x-ui.card>

            <div class="space-y-4">
                @if ($canStatus && $statuses !== [])
                    <x-ui.card title="Move status">
                        <form method="POST" action="{{ route('admin.projects.status', $project) }}" class="space-y-3">
                            @csrf
                            <x-ui.form.select name="status" :options="$statuses" placeholder="Choose a status" required aria-label="New status" />
                            <x-ui.form.input name="reason" label="Reason" help="Required when pausing or cancelling." maxlength="255" />
                            <x-ui.button type="submit" class="w-full" icon="arrow-right">Apply</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                <x-ui.card title="Milestones">
                    @if ($project->milestones->isEmpty())
                        <p class="text-sm text-slate-500 dark:text-slate-400">No milestones yet.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($project->milestones as $milestone)
                                <li class="flex items-center justify-between gap-3">
                                    <a href="{{ route('admin.milestones.show', $milestone) }}" class="truncate text-sm text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $milestone->name }}</a>
                                    <x-ui.badge :color="$milestone->status->color()" size="xs">{{ app_number((float) $milestone->progress_percent, 0) }}%</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <x-slot:footer>
                        <x-ui.button variant="secondary" size="sm" :href="route('admin.milestones.index', $project)">Manage milestones</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>

                {{-- phase-11 §8.2: the payment trail. `$payments` is null — not empty — when the
                     viewer may not read receipts, so "nothing yet" and "not yours to see" stay
                     distinguishable rather than both rendering as an empty list. --}}
                @if ($payments !== null)
                    <x-ui.card title="Payments received">
                        @if ($payments->isEmpty())
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                No client money recorded against this project yet.
                            </p>
                        @else
                            <ul class="space-y-3">
                                @foreach ($payments as $payment)
                                    <li class="flex items-start justify-between gap-3 border-b border-slate-100 pb-2 last:border-0 dark:border-slate-800">
                                        <div class="min-w-0">
                                            @can('project_payments.view')
                                                <a href="{{ route('admin.project-payments.show', $payment) }}"
                                                   class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                                    {{ $payment->payment_no }}
                                                </a>
                                            @else
                                                <span class="block font-mono text-xs font-semibold text-slate-900 dark:text-white">{{ $payment->payment_no }}</span>
                                            @endcan

                                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                                {{ app_date($payment->paid_on) }} · {{ $payment->payment_method->label() }}
                                            </span>

                                            @if ($payment->milestone)
                                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $payment->milestone->title }}</span>
                                            @endif

                                            @if ($payment->collaborator)
                                                <span class="block font-mono text-xs text-slate-400">{{ $payment->collaborator->collaborator_code }}</span>
                                            @endif
                                        </div>

                                        <div class="shrink-0 text-right">
                                            {{-- The **net** figure, not the gross: a trail that showed
                                                 what arrived and hid what went back would be the wrong
                                                 number to read at a glance. --}}
                                            <span class="block text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                                                {{ money($payment->net_received_amount) }}
                                            </span>

                                            @if (bccomp((string) $payment->refunded_amount, '0.00', 2) === 1)
                                                <span class="block text-xs text-rose-600 dark:text-rose-400">
                                                    {{ money($payment->refunded_amount) }} refunded
                                                </span>
                                            @endif

                                            @if ($payment->status !== \App\Enums\ReceivedPaymentStatus::Cleared)
                                                <x-ui.badge :color="$payment->status->color()" size="xs">{{ $payment->status->label() }}</x-ui.badge>
                                            @elseif ($payment->is_advance)
                                                <x-ui.badge color="sky" size="xs">advance</x-ui.badge>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($payments->count() === 20)
                                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                                    The twenty most recent. The register has the rest.
                                </p>
                            @endif
                        @endif

                        <x-slot:footer>
                            <x-ui.button variant="secondary" size="sm"
                                         :href="route('admin.project-payments.index', ['project' => $project->id])">
                                Open the register
                            </x-ui.button>
                        </x-slot:footer>
                    </x-ui.card>
                @endif
            </div>
        </div>
    </div>
@endsection
