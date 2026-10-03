@extends('layouts.admin')

@section('title', 'Employees')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('employees.create');
    $canExport = (bool) $user?->can('employees.export');
@endphp

@section('header')
    <x-ui.page-header title="Employees" subtitle="Everybody on the payroll, and everybody who was." icon="users">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" :href="route('admin.employees.export', ['format' => 'csv'])" icon="arrow-down-tray">Export</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button :href="route('admin.employees.create')" icon="user-plus">Add employee</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-4">
            <x-ui.form.input name="q" label="Search" placeholder="Name, code or email" :value="$term" />
            <x-ui.form.select name="department_id" label="Department" placeholder="Every department">
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected((int) request('department_id') === $department->id)>{{ $department->name }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.employees.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$employees->total() . ' employee(s)'">
        <x-ui.table :is-empty="$employees->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Department</th>
                <th class="px-4 py-3 text-left font-semibold">Joined</th>
                @if ($showMoney)
                    <th class="px-4 py-3 text-right font-semibold">Gross</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($employees as $employee)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.employees.show', $employee) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $employee->name }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $employee->employee_code }}
                            @if ($employee->designation) · {{ $employee->designation->title }} @endif
                            @if ($employee->is_attendance_exempt) · attendance exempt @endif
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $employee->department?->name ?? '—' }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $employee->workShift?->name ?? 'No shift' }}</span>
                    </td>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        <span class="block">{{ app_date($employee->joining_date) }}</span>
                        @if ($employee->exit_date)
                            <span class="block text-xs text-rose-600 dark:text-rose-400">left {{ app_date($employee->exit_date) }}</span>
                        @endif
                    </td>
                    @if ($showMoney)
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            {{ money((string) $employee->current_gross_salary) }}
                        </td>
                    @endif
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$employee->status->color()" size="xs">{{ $employee->status->label() }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <x-ui.icon-button icon="eye" size="sm" label="Open {{ $employee->name }}"
                                              :href="route('admin.employees.show', $employee)" />
                            @if ($canEdit)
                                <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $employee->name }}"
                                                  :href="route('admin.employees.edit', $employee)" />
                            @endif
                            {{-- Only somebody with no attendance, leave or payroll on record can be archived;
                                 everybody else is exited through their status. The destroy route asks the
                                 policy again. --}}
                            @if ($canDelete && ! isset($withHistory[$employee->id]))
                                <x-ui.confirm
                                    :action="route('admin.employees.destroy', $employee)"
                                    :title="'Archive '.$employee->name.'?'"
                                    message="Nothing has been recorded against this employee, so the record moves to the trash and can be restored."
                                    confirm-label="Archive employee"
                                >
                                    <x-slot:trigger>
                                        <x-ui.icon-button icon="trash" size="sm" variant="danger" label="Delete {{ $employee->name }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="users" title="No employees found"
                    description="Add the first one, or widen the filters." />
            </x-slot:empty>
        </x-ui.table>

        @if ($employees->hasPages())
            <div class="mt-4">{{ $employees->links() }}</div>
        @endif
    </x-ui.card>
@endsection
