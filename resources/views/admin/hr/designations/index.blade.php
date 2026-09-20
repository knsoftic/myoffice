@extends('layouts.admin')

@section('title', 'Designations')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('designations.create');
    $canEdit = (bool) $user?->can('designations.edit');
    $canDelete = (bool) $user?->can('designations.delete');
@endphp

@section('header')
    <x-ui.page-header title="Designations" subtitle="Job titles, and the ladder they sit on." icon="identification">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.departments.index')" icon="building-office">Departments</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form method="GET" class="flex items-end gap-2">
                <div class="flex-1">
                    <x-ui.form.input name="q" label="Search" placeholder="Title" :value="$term" />
                </div>
                <x-ui.button type="submit" variant="secondary" icon="magnifying-glass">Search</x-ui.button>
            </form>

            <x-ui.card title="All designations" :subtitle="$designations->total() . ' in total'">
                <x-ui.table :is-empty="$designations->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Title</th>
                        <th class="px-4 py-3 text-left font-semibold">Department</th>
                        <th class="px-4 py-3 text-right font-semibold">Level</th>
                        <th class="px-4 py-3 text-right font-semibold">People</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($designations as $designation)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-900 dark:text-white">{{ $designation->title }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $designation->code ?? '—' }}
                                    @unless ($designation->is_active) · inactive @endunless
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $designation->department?->name ?? 'Any department' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $designation->level }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $designation->employees_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canEdit)
                                        <x-ui.button variant="ghost" size="sm" icon="pencil"
                                            x-on:click="$dispatch('open-modal', 'desig-edit-{{ $designation->id }}')">Edit</x-ui.button>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.designations.destroy', $designation)"
                                            title="Remove {{ $designation->title }}?"
                                            message="Refused while anybody still holds the title."
                                            confirm-label="Remove title"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" label="Remove designation" variant="danger" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="identification" title="No designations yet"
                            description="A title is optional on an employee, but it is what a slip prints." />
                    </x-slot:empty>
                </x-ui.table>

                @if ($designations->hasPages())
                    <div class="mt-4">{{ $designations->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        @if ($canCreate)
            <x-ui.card title="Add a designation">
                <form method="POST" action="{{ route('admin.designations.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input name="title" label="Title" required maxlength="150" :value="old('title')" />
                    <x-ui.form.input name="code" label="Code" maxlength="32" :value="old('code')" />
                    <x-ui.form.select name="department_id" label="Department" placeholder="Any department">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((int) old('department_id') === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </x-ui.form.select>
                    <x-ui.form.input type="number" name="level" label="Level" :value="old('level', 0)"
                        help="Higher is more senior. Reports group by it." />
                    <x-ui.button type="submit" class="w-full" icon="plus">Add designation</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    @if ($canEdit)
        @foreach ($designations as $designation)
            <x-ui.modal :name="'desig-edit-' . $designation->id" title="Edit {{ $designation->title }}" icon="pencil">
                <form method="POST" action="{{ route('admin.designations.update', $designation) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <x-ui.form.input name="title" label="Title" required maxlength="150" :value="$designation->title" />
                    <x-ui.form.input name="code" label="Code" maxlength="32" :value="$designation->code" />
                    <x-ui.form.select name="department_id" label="Department" placeholder="Any department">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected($designation->department_id === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </x-ui.form.select>
                    <x-ui.form.input type="number" name="level" label="Level" :value="$designation->level" />
                    <x-ui.form.checkbox name="is_active" label="Active" :checked="$designation->is_active" with-hidden />
                    <x-ui.button type="submit" class="w-full">Save designation</x-ui.button>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
