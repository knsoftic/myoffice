@extends('layouts.admin')

@section('title', 'Attendance corrections')

@php
    $user = auth()->user();
    $canApprove = (bool) $user?->can('attendance.approve');
    $canReject = (bool) $user?->can('attendance.reject');
@endphp

@section('header')
    <x-ui.page-header title="Attendance corrections" :subtitle="$pendingCount . ' waiting on a decision'" icon="wrench-screwdriver">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.attendance.index')" icon="calendar-days">Daily register</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-56">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.attendance-corrections.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$corrections->total() . ' correction(s)'">
        <x-ui.table :is-empty="$corrections->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">Asked for</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($corrections as $correction)
                <tr>
                    <td class="px-4 py-3">
                        <span class="block font-medium text-slate-900 dark:text-white">{{ $correction->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $correction->employee?->employee_code }} · asked by {{ $correction->requester?->name ?? 'the system' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ app_date($correction->attendance_date) }}</td>
                    <td class="px-4 py-3">
                        <span class="block text-sm text-slate-900 dark:text-white">{{ $correction->correction_type->label() }}</span>
                        <span class="block max-w-sm truncate text-xs text-slate-500 dark:text-slate-400">{{ $correction->reason }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$correction->status->color()" size="xs">{{ $correction->status->label() }}</x-ui.badge>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $correction->source->label() }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if ($correction->status->value === 'pending')
                            <div class="flex items-center justify-end gap-1">
                                @if ($canApprove)
                                    <form method="POST" action="{{ route('admin.attendance-corrections.approve', $correction) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="check">Approve</x-ui.button>
                                    </form>
                                @endif
                                @if ($canReject)
                                    <x-ui.button variant="ghost" size="sm" icon="x-mark"
                                        x-on:click="$dispatch('open-modal', 'reject-{{ $correction->id }}')">Refuse</x-ui.button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="wrench-screwdriver" title="Nothing waiting"
                    description="Every manual change to attendance passes through here, so this queue being empty is the normal state." />
            </x-slot:empty>
        </x-ui.table>

        @if ($corrections->hasPages())
            <div class="mt-4">{{ $corrections->links() }}</div>
        @endif
    </x-ui.card>

    @if ($canReject)
        @foreach ($corrections as $correction)
            @continue($correction->status->value !== 'pending')
            <x-ui.modal :name="'reject-' . $correction->id" title="Refuse this correction" icon="x-mark">
                <form method="POST" action="{{ route('admin.attendance-corrections.reject', $correction) }}" class="space-y-3">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $correction->employee?->name }} asked for {{ app_date($correction->attendance_date) }} to be
                        changed. The day itself is not touched by a refusal.
                    </p>
                    <x-ui.form.textarea name="review_comment" label="Why" rows="3" required
                        help="The employee sees this, and “rejected” on its own is not an answer." />
                    <x-ui.button type="submit" variant="danger" class="w-full">Refuse correction</x-ui.button>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
