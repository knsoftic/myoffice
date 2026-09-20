@extends('layouts.admin')

@section('title', 'Departments')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('departments.create');
    $canEdit = (bool) $user?->can('departments.edit');
    $canAssign = (bool) $user?->can('departments.assign');
    $canDelete = (bool) $user?->can('departments.delete');
@endphp

@section('header')
    <x-ui.page-header title="Departments" subtitle="The org chart every HR screen groups by." icon="building-office">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.designations.index')" icon="identification">Designations</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form method="GET" class="flex items-end gap-2">
                <div class="flex-1">
                    <x-ui.form.input name="q" label="Search" placeholder="Name or code" :value="$term" />
                </div>
                <x-ui.button type="submit" variant="secondary" icon="magnifying-glass">Search</x-ui.button>
            </form>

            <x-ui.card title="All departments" :subtitle="$departments->total() . ' in total'">
                <x-ui.table :is-empty="$departments->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Department</th>
                        <th class="px-4 py-3 text-left font-semibold">Head</th>
                        <th class="px-4 py-3 text-right font-semibold">People</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($departments as $department)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-900 dark:text-white">{{ $department->name }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $department->code }}
                                    @if ($department->branch) · {{ $department->branch->name }} @endif
                                    @unless ($department->is_active) · inactive @endunless
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $department->head?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $department->employees_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canAssign)
                                        <x-ui.button variant="ghost" size="sm" icon="user-circle"
                                            x-on:click="$dispatch('open-modal', 'dept-head-{{ $department->id }}')">Head</x-ui.button>
                                    @endif
                                    @if ($canEdit)
                                        <x-ui.button variant="ghost" size="sm" icon="pencil"
                                            x-on:click="$dispatch('open-modal', 'dept-edit-{{ $department->id }}')">Edit</x-ui.button>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.departments.destroy', $department)"
                                            title="Remove {{ $department->name }}?"
                                            message="Refused while anybody is still in it — move them first."
                                            confirm-label="Remove department"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" label="Remove department" variant="danger" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="building-office" title="No departments yet"
                            description="Every employee belongs to one, so this is the first thing to set up." />
                    </x-slot:empty>
                </x-ui.table>

                @if ($departments->hasPages())
                    <div class="mt-4">{{ $departments->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        @if ($canCreate)
            <x-ui.card title="Add a department">
                <form method="POST" action="{{ route('admin.departments.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input name="code" label="Code" required maxlength="32" :value="old('code')"
                        help="Uppercased automatically. It appears on reports." />
                    <x-ui.form.input name="name" label="Name" required maxlength="150" :value="old('name')" />
                    <x-ui.form.textarea name="description" label="Description" rows="2" :value="old('description')" />
                    <x-ui.form.input type="number" name="sort_order" label="Sort order" :value="old('sort_order', 0)" />
                    <x-ui.button type="submit" class="w-full" icon="plus">Add department</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    @foreach ($departments as $department)
        @if ($canAssign)
            <x-ui.modal :name="'dept-head-' . $department->id" title="Head of {{ $department->name }}" icon="user-circle">
                <form method="POST" action="{{ route('admin.departments.head', $department) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.select name="head_employee_id" label="Head of department" placeholder="Nobody">
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected($department->head_employee_id === $employee->id)>
                                {{ $employee->name }} ({{ $employee->employee_code }})
                            </option>
                        @endforeach
                    </x-ui.form.select>
                    <x-ui.form.input name="reason" label="Reason" maxlength="255"
                        help="Only needed when the head works in another department — say why, because that is usually a mis-click." />
                    <x-ui.button type="submit" class="w-full">Save head</x-ui.button>
                </form>
            </x-ui.modal>
        @endif

        @if ($canEdit)
            <x-ui.modal :name="'dept-edit-' . $department->id" title="Edit {{ $department->name }}" icon="pencil">
                <form method="POST" action="{{ route('admin.departments.update', $department) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <x-ui.form.input name="code" label="Code" required maxlength="32" :value="$department->code" />
                    <x-ui.form.input name="name" label="Name" required maxlength="150" :value="$department->name" />
                    <x-ui.form.textarea name="description" label="Description" rows="2" :value="$department->description" />
                    <x-ui.form.input type="number" name="sort_order" label="Sort order" :value="$department->sort_order" />
                    <x-ui.form.checkbox name="is_active" label="Active" :checked="$department->is_active" with-hidden />
                    <x-ui.button type="submit" class="w-full">Save department</x-ui.button>
                </form>
            </x-ui.modal>
        @endif
    @endforeach
@endsection
