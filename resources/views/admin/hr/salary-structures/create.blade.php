@extends('layouts.admin')

@section('title', 'New salary version — ' . $employee->name)

@section('header')
    <x-ui.page-header
        :title="'New salary version — ' . $employee->name"
        subtitle="The version in force is closed the day before this one starts."
        icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.employees.salary-structures.index', $employee)">Back to the timeline</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.employees.salary-structures.store', $employee) }}" class="max-w-3xl space-y-4">
        @csrf

        <x-ui.card title="The new version">
            <div class="space-y-3">
                <x-ui.form.input type="date" name="effective_from" label="In force from" required
                    :value="old('effective_from', now()->addMonthNoOverflow()->startOfMonth()->toDateString())"
                    help="A date inside a period a payroll run has already locked is refused." />
                <x-ui.form.input type="number" step="0.01" min="0" name="basic_salary" label="Basic salary" required
                    :value="old('basic_salary', $current ? (string) $current->basic_salary : '0.00')" />
                <x-ui.form.textarea name="change_reason" label="Why this version exists" rows="2" required
                    :value="old('change_reason')"
                    placeholder="Annual increment 2026" />
            </div>
        </x-ui.card>

        <x-ui.card title="Allowances and deductions" subtitle="Leave an amount empty to leave the component out.">
            @if ($components->isEmpty())
                <x-ui.empty-state icon="adjustments-horizontal" title="No components defined"
                    description="Add them under HR Setup — a structure is basic plus whatever is defined there." />
            @else
                <div class="space-y-3">
                    @foreach ($components as $index => $component)
                        @php
                            $existing = $current?->components->firstWhere('salary_component_id', $component->id);
                            $needsRate = $component->calculation_type->needsRate();
                        @endphp
                        <div class="grid grid-cols-12 items-end gap-3 border-b border-slate-100 pb-3 dark:border-slate-800">
                            <div class="col-span-5">
                                <span class="block text-sm font-medium text-slate-900 dark:text-white">{{ $component->name }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $component->component_group->label() }} · {{ $component->calculation_type->label() }}
                                </span>
                                <input type="hidden" name="components[{{ $index }}][salary_component_id]" value="{{ $component->id }}">
                            </div>
                            <div class="col-span-3">
                                <x-ui.form.input type="number" step="0.01" min="0"
                                    :name="'components[' . $index . '][amount]'" label="Amount"
                                    :value="$existing ? (string) $existing->amount : null"
                                    :disabled="$needsRate" />
                            </div>
                            <div class="col-span-3">
                                <x-ui.form.input type="number" step="0.0001" min="0" max="100"
                                    :name="'components[' . $index . '][rate]'" label="Rate (%)"
                                    :value="$existing ? (string) $existing->rate : (string) $component->default_rate"
                                    :disabled="! $needsRate" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.employees.salary-structures.index', $employee)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save this version</x-ui.button>
        </div>
    </form>
@endsection
