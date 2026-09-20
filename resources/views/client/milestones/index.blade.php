@extends('layouts.panel')

@section('title', 'Milestones')

{{--
    Client panel milestones — client.milestones.index (phase-06 §7.7, §8.11).

    The payment value only appears for a client who also holds client_portal.invoices, and the column is
    added to the SELECT rather than blanked here — MilestonesSection decides, this template only renders
    what arrived.
--}}

@php
    $milestones = $items ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
    $showAmounts = auth()->user()?->can('client_portal.invoices') ?? false;
@endphp

@section('header')
    @include('client.partials.header', [
        'client' => $client,
        'title' => 'Milestones',
        'subtitle' => 'The stages of your projects, and where each one stands.',
        'icon' => 'flag',
    ])
@endsection

@section('content')
    @if ($milestones->isEmpty())
        <x-ui.empty-state icon="flag" title="No milestones yet" description="Once your project is broken into stages they will show here." />
    @else
        <div class="space-y-4">
            <x-ui.card>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($milestones as $milestone)
                        @php $percent = (float) $milestone->progress_percent; @endphp
                        <li class="py-3">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $milestone->name }}</span>
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $milestone->project?->name }}
                                        @if ($milestone->deadline) · due {{ app_date($milestone->deadline) }} @endif
                                        @if ($showAmounts && $milestone->amount !== null) · {{ money((string) $milestone->amount) }} @endif
                                    </span>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="text-xs tabular-nums text-slate-600 dark:text-slate-300">{{ app_number($percent, 0) }}%</span>
                                    <x-ui.badge :color="$milestone->status->color()" size="xs">{{ $milestone->status->label() }}</x-ui.badge>
                                </div>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, $percent)) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.pagination-summary :paginator="$milestones" label="milestones" />
        </div>
    @endif
@endsection
