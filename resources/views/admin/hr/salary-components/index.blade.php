@extends('layouts.admin')

@section('title', 'Salary components')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('salary_components.create');
    $canEdit = (bool) $user?->can('salary_components.edit');
    $canToggle = (bool) $user?->can('salary_components.change_status');
    $canDelete = (bool) $user?->can('salary_components.delete');
@endphp

@section('header')
    <x-ui.page-header title="Salary components" subtitle="The allowances and deductions a structure is built from." icon="adjustments-horizontal" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            Which <strong class="font-semibold text-slate-900 dark:text-white">side</strong> a component falls on
            comes from its group and is never typed — a component filed as an allowance can never be saved as a
            deduction. Unpaid leave, late, advance recovery, overtime and tax are produced by payroll itself and
            are not offered here.
        </p>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @forelse ($components as $side => $rows)
                <x-ui.card :title="$side" :subtitle="$rows->count() . ' component(s)'">
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Component</th>
                            <th class="px-4 py-3 text-left font-semibold">Group</th>
                            <th class="px-4 py-3 text-left font-semibold">Calculation</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </x-slot:head>

                        @foreach ($rows as $salaryComponent)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="block font-medium text-slate-900 dark:text-white">{{ $salaryComponent->name }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        {{ $salaryComponent->code }}
                                        @if ($salaryComponent->is_system) · system @endif
                                        @unless ($salaryComponent->is_active) · retired @endunless
                                        @unless ($salaryComponent->is_taxable) · not taxable @endunless
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :color="$salaryComponent->component_group->color()" size="xs">{{ $salaryComponent->component_group->label() }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                    {{ $salaryComponent->calculation_type->label() }}
                                    @if ($salaryComponent->calculation_type->needsRate())
                                        · {{ app_number((float) $salaryComponent->default_rate, 2) }}%
                                    @else
                                        · {{ money((string) $salaryComponent->default_amount) }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($canEdit)
                                            <x-ui.button variant="ghost" size="sm" icon="pencil"
                                                x-on:click="$dispatch('open-modal', 'component-{{ $salaryComponent->id }}')">Edit</x-ui.button>
                                        @endif
                                        @if ($canToggle)
                                            <form method="POST" action="{{ route('admin.salary-components.toggle', $salaryComponent) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="ghost" size="sm">{{ $salaryComponent->is_active ? 'Retire' : 'Restore' }}</x-ui.button>
                                            </form>
                                        @endif
                                        @if ($canDelete && ! $salaryComponent->is_system)
                                            <x-ui.confirm
                                                :action="route('admin.salary-components.destroy', $salaryComponent)"
                                                title="Remove {{ $salaryComponent->name }}?"
                                                message="Refused while any structure uses it — retire it instead, and past slips keep what they said."
                                                confirm-label="Remove component"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.icon-button icon="trash" label="Remove component" variant="danger" />
                                                </x-slot:trigger>
                                            </x-ui.confirm>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty-state icon="adjustments-horizontal" title="No components yet"
                        description="A salary structure is basic plus whatever is defined here." />
                </x-ui.card>
            @endforelse
        </div>

        @if ($canCreate)
            <x-ui.card title="Add a component">
                <form method="POST" action="{{ route('admin.salary-components.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input name="code" label="Code" required maxlength="32" :value="old('code')"
                        help="Printed on the slip, so it has to mean one thing." />
                    <x-ui.form.input name="name" label="Name" required maxlength="100" :value="old('name')" />
                    <x-ui.form.select name="component_group" label="Group" :options="$groups"
                        :selected="old('component_group', 'allowance')" required />
                    <x-ui.form.select name="calculation_type" label="Calculation" :options="$calculations"
                        :selected="old('calculation_type', 'fixed')" required />
                    <x-ui.form.input type="number" step="0.01" min="0" name="default_amount" label="Default amount"
                        :value="old('default_amount', '0.00')" />
                    <x-ui.form.input type="number" step="0.0001" min="0" max="100" name="default_rate" label="Default rate (%)"
                        :value="old('default_rate', '0.0000')" />
                    <x-ui.form.checkbox name="is_taxable" label="Part of the taxable base" :checked="(bool) old('is_taxable', true)" />
                    <x-ui.button type="submit" class="w-full" icon="plus">Add component</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    @if ($canEdit)
        @foreach ($components as $rows)
            @foreach ($rows as $salaryComponent)
                <x-ui.modal :name="'component-' . $salaryComponent->id" title="Edit {{ $salaryComponent->name }}" icon="pencil">
                    <form method="POST" action="{{ route('admin.salary-components.update', $salaryComponent) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <x-ui.form.input name="code" label="Code" required maxlength="32" :value="$salaryComponent->code"
                            :disabled="$salaryComponent->is_system"
                            :help="$salaryComponent->is_system ? 'A system component keeps its code — past slips carry it.' : null" />
                        <x-ui.form.input name="name" label="Name" required maxlength="100" :value="$salaryComponent->name" />
                        @unless ($salaryComponent->is_system)
                            <x-ui.form.select name="component_group" label="Group" :options="$groups"
                                :selected="$salaryComponent->component_group->value" required />
                        @endunless
                        <x-ui.form.select name="calculation_type" label="Calculation" :options="$calculations"
                            :selected="$salaryComponent->calculation_type->value" required />
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.form.input type="number" step="0.01" min="0" name="default_amount" label="Default amount"
                                :value="(string) $salaryComponent->default_amount" />
                            <x-ui.form.input type="number" step="0.0001" min="0" max="100" name="default_rate" label="Default rate (%)"
                                :value="(string) $salaryComponent->default_rate" />
                        </div>
                        <x-ui.form.input name="print_label" label="Label on the slip" maxlength="100" :value="$salaryComponent->print_label" />
                        <x-ui.form.checkbox name="is_taxable" label="Part of the taxable base" :checked="$salaryComponent->is_taxable" />
                        <x-ui.form.checkbox name="is_active" label="Active" :checked="$salaryComponent->is_active" with-hidden />
                        <x-ui.button type="submit" class="w-full">Save component</x-ui.button>
                    </form>
                </x-ui.modal>
            @endforeach
        @endforeach
    @endif
@endsection
