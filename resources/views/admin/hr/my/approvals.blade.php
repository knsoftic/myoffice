@extends('layouts.admin')

@section('title', 'My approvals')

@section('header')
    <x-ui.page-header title="My approvals" subtitle="Leave waiting on a decision from you." icon="inbox" />
@endsection

@section('content')
    <div class="space-y-4">
        <x-ui.card title="Waiting on you by name" :subtitle="$named->count() . ' request(s)'">
            @if ($named->isEmpty())
                <p class="text-sm text-slate-500 dark:text-slate-400">Nothing is addressed to you personally.</p>
            @else
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($named as $approval)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.leaves.show', $approval->leave_request_id) }}"
                                   class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $approval->leaveRequest?->employee?->name }}
                                </a>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $approval->leaveRequest?->request_number }} ·
                                    {{ $approval->leaveRequest?->leaveType?->name }} ·
                                    {{ app_date($approval->leaveRequest?->from_date) }} – {{ app_date($approval->leaveRequest?->to_date) }}
                                </span>
                            </div>
                            <x-ui.button variant="ghost" size="sm" :href="route('admin.leaves.show', $approval->leave_request_id)">Open</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if ($byPermission->isNotEmpty())
            <x-ui.card title="Waiting on anybody who can approve" :subtitle="$byPermission->count() . ' request(s)'">
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($byPermission as $approval)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.leaves.show', $approval->leave_request_id) }}"
                                   class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $approval->leaveRequest?->employee?->name }}
                                </a>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $approval->leaveRequest?->request_number }} ·
                                    {{ app_date($approval->leaveRequest?->from_date) }} – {{ app_date($approval->leaveRequest?->to_date) }}
                                </span>
                            </div>
                            <x-ui.button variant="ghost" size="sm" :href="route('admin.leaves.show', $approval->leave_request_id)">Open</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
@endsection
