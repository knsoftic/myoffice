@extends('layouts.admin')

@section('title', 'My HR profile')

@section('header')
    <x-ui.page-header title="My HR profile" :subtitle="$employee->employee_code" icon="user">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.my.attendance.index')" icon="clock">My attendance</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.my.leave.index')" icon="calendar">My leave</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            This page is read-only. Changing a department or a joining date changes the inputs to a salary, so
            corrections go through HR — ask them, and the change is recorded with a reason.
        </p>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="Employment">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt>
                    <dd class="mt-1"><x-ui.badge :color="$employee->status->color()" size="xs">{{ $employee->status->label() }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Employment</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->employment_type->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Department</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->department?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Designation</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->designation?->title ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Reports to</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->manager?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Joined</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ app_date($employee->joining_date) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Shift</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->workShift?->name ?? 'Branch default' }}</dd>
                </div>
                @if ($showMoney)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Current gross</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ money((string) $employee->current_gross_salary) }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Contact">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Email</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Phone</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->phone ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">In an emergency</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                        {{ $employee->emergency_contact_name ?? '—' }}
                        @if ($employee->emergency_contact_phone) · {{ $employee->emergency_contact_phone }} @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    </div>
@endsection
