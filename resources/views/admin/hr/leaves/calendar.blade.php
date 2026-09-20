@extends('layouts.admin')

@section('title', 'Leave calendar')

@section('header')
    <x-ui.page-header title="Leave calendar" :subtitle="app_date($month, 'F Y')" icon="calendar-days">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.leaves.index')" icon="list-bullet">List view</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex items-end gap-3">
            <div class="w-44">
                <x-ui.form.input type="month" name="month" label="Month" :value="app_date($month, 'Y-m')" />
            </div>
            <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$requests->count() . ' absence(s) touching this month'">
        @if ($requests->isEmpty())
            <x-ui.empty-state icon="calendar-days" title="Nobody is away" description="No pending or approved leave overlaps this month." />
        @else
            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($requests as $leave)
                    <li class="flex items-start justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('admin.leaves.show', $leave) }}"
                               class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                {{ $leave->employee?->name }}
                            </a>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                {{ $leave->leaveType?->name }} ·
                                {{ app_date($leave->from_date) }} – {{ app_date($leave->to_date) }} ·
                                {{ app_number((float) $leave->total_days, 2) }} day(s) counted
                                @if ($leave->days->count() > (int) $leave->days->where('is_counted', true)->count())
                                    ({{ $leave->days->where('is_counted', false)->count() }} skipped as non-working)
                                @endif
                            </span>
                        </div>
                        <x-ui.badge :color="$leave->status->color()" size="xs">{{ $leave->status->label() }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
@endsection
