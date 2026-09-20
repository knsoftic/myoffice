@extends('layouts.admin')

@section('title', 'Leave requests')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('leaves.create');
@endphp

@section('header')
    <x-ui.page-header title="Leave requests" :subtitle="$pendingCount . ' waiting on a decision'" icon="calendar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.leaves.calendar')" icon="calendar-days">Calendar</x-ui.button>
            @if ($canCreate)
                <x-ui.button :href="route('admin.leaves.create')" icon="plus">Apply for leave</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-48">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            </div>
            <div class="w-56">
                <x-ui.form.select name="leave_type_id" label="Leave type" placeholder="Every type">
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected((int) request('leave_type_id') === $type->id)>{{ $type->name }}</option>
                    @endforeach
                </x-ui.form.select>
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.leaves.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$requests->total() . ' request(s)'">
        <x-ui.table :is-empty="$requests->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Request</th>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Dates</th>
                <th class="px-4 py-3 text-right font-semibold">Days</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($requests as $leave)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.leaves.show', $leave) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $leave->request_number }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $leave->leaveType?->name }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $leave->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $leave->employee?->employee_code }}</span>
                    </td>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_date($leave->from_date) }}
                        @unless ($leave->from_date->equalTo($leave->to_date))
                            – {{ app_date($leave->to_date) }}
                        @endunless
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $leave->total_days, 2) }}</td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$leave->status->color()" size="xs">{{ $leave->status->label() }}</x-ui.badge>
                        @if ($leave->status->value === 'pending')
                            <span class="block text-xs text-slate-500 dark:text-slate-400">level {{ $leave->current_approval_level }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="calendar" title="No leave requests"
                    description="Nobody has applied, or the filters exclude everything." />
            </x-slot:empty>
        </x-ui.table>

        @if ($requests->hasPages())
            <div class="mt-4">{{ $requests->links() }}</div>
        @endif
    </x-ui.card>
@endsection
