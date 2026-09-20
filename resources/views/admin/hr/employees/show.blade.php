@extends('layouts.admin')

@section('title', $employee->name)

@php
    $user = auth()->user();
    $canEdit = $user?->can('update', $employee) === true;
    $canStatus = $user?->can('changeStatus', $employee) === true;
    $canPrint = $user?->can('print', $employee) === true;
@endphp

@section('header')
    <x-ui.page-header :title="$employee->name" :subtitle="$employee->employee_code . ' · ' . ($employee->designation?->title ?? 'No title')" icon="user">
        <x-slot:actions>
            @if ($canPrint)
                <x-ui.button variant="ghost" :href="route('admin.employees.print', $employee)" icon="printer">Print</x-ui.button>
            @endif
            @if ($canEdit)
                <x-ui.button :href="route('admin.employees.edit', $employee)" icon="pencil">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($employee->is_attendance_exempt)
        <x-ui.card class="mb-4">
            <p class="text-sm text-amber-700 dark:text-amber-300">
                <strong class="font-semibold">Exempt from attendance.</strong>
                {{ $employee->name }} is never marked absent or late and is always paid a full day. That is a pay
                decision, which is why it is stated here rather than hidden in a checkbox.
            </p>
        </x-ui.card>
    @endif

    @if ($employee->work_shift_id === null)
        <x-ui.card class="mb-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <strong class="font-semibold text-slate-900 dark:text-white">No shift assigned.</strong>
                Late and early-leave minutes are meaningless without one, so this person's days are judged only on
                the hours they actually work.
            </p>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
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
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Reports to</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->manager?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Joined</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ app_date($employee->joining_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Shift</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $employee->workShift?->name ?? 'Branch default' }}</dd>
                    </div>
                    @if ($employee->exit_date)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Left on</dt>
                            <dd class="mt-1 text-sm text-rose-600 dark:text-rose-400">
                                {{ app_date($employee->exit_date) }} — {{ $employee->exit_reason }}
                            </dd>
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
                            @if ($employee->emergency_contact_relation) ({{ $employee->emergency_contact_relation }}) @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($directReports->isNotEmpty())
                <x-ui.card title="Direct reports" :subtitle="$directReports->count() . ' people'">
                    <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($directReports as $report)
                            <li class="py-2">
                                <a href="{{ route('admin.employees.show', $report) }}"
                                   class="text-sm text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $report->name }}
                                </a>
                                <span class="text-xs text-slate-500 dark:text-slate-400"> · {{ $report->employee_code }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            @if ($showMoney)
                <x-ui.card title="Salary" subtitle="The version in force today.">
                    @if ($structure === null)
                        <x-ui.empty-state icon="banknotes" title="No salary structure"
                            description="Payroll skips this person by name rather than paying them zero." />
                    @else
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-slate-500 dark:text-slate-400">Basic</dt>
                                <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $structure->basic_salary) }}</dd>
                            </div>
                            @foreach ($structure->components as $line)
                                <div class="flex justify-between">
                                    <dt class="text-slate-500 dark:text-slate-400">{{ $line->component_name }}</dt>
                                    <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $line->amount) }}</dd>
                                </div>
                            @endforeach
                            <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                                <dt class="text-slate-700 dark:text-slate-200">Gross</dt>
                                <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $structure->gross_salary) }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                            Version {{ $structure->version }}, in force from {{ app_date($structure->effective_from) }}.
                        </p>
                    @endif
                </x-ui.card>
            @endif

            @if ($canStatus)
                <x-ui.card title="Change status">
                    <form method="POST" action="{{ route('admin.employees.status', $employee) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="status" label="New status" :options="$statuses" :selected="$employee->status->value" required />
                        <x-ui.form.textarea name="reason" label="Reason" rows="2"
                            help="Mandatory for anything but a return to active." />
                        <x-ui.form.input type="date" name="exit_date" label="Exit date"
                            help="Only for a resignation or a termination. The final month is still paid." />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Save status</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card title="Login">
                @if ($employee->user)
                    <p class="text-sm text-slate-900 dark:text-white">{{ $employee->user->email }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Account status: {{ $employee->user->status->label() }}</p>
                    @if ($canEdit)
                        <form method="POST" action="{{ route('admin.employees.user.unlink', $employee) }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm">Unlink login</x-ui.button>
                        </form>
                    @endif
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        No login. An employee record exists perfectly well without one — a labourer, or somebody
                        whose account was closed.
                    </p>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
