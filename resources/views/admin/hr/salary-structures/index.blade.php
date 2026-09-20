@extends('layouts.admin')

@section('title', $employee->name . ' — salary')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('salary_structures.create');
    $canCancel = (bool) $user?->can('salary_structures.change_status');
@endphp

@section('header')
    <x-ui.page-header :title="$employee->name . ' — salary history'" :subtitle="$employee->employee_code" icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.employees.show', $employee)">Profile</x-ui.button>
            @if ($canCreate)
                <x-ui.button :href="route('admin.employees.salary-structures.create', $employee)" icon="plus">New version</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($versions->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="banknotes" title="No salary structure yet"
                description="Payroll skips an employee with no structure by name rather than paying them zero." />
        </x-ui.card>
    @else
        <div class="space-y-4">
            @foreach ($versions as $version)
                <x-ui.card :title="'Version ' . $version->version"
                           :subtitle="app_date($version->effective_from) . ' – ' . ($version->effective_to ? app_date($version->effective_to) : 'open')">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <x-ui.badge :color="$version->status->color()" size="xs">{{ $version->status->label() }}</x-ui.badge>
                        @if ($canCancel && $version->status->value === 'scheduled')
                            <x-ui.button variant="ghost" size="sm"
                                x-on:click="$dispatch('open-modal', 'cancel-structure-{{ $version->id }}')">Withdraw</x-ui.button>
                        @endif
                    </div>

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Basic</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $version->basic_salary) }}</dd>
                        </div>
                        @foreach ($version->components as $line)
                            <div class="flex justify-between">
                                <dt class="text-slate-500 dark:text-slate-400">
                                    {{ $line->component_name }}
                                    <span class="text-xs">({{ $line->calculation_type->label() }})</span>
                                </dt>
                                <dd class="tabular-nums {{ $line->side->value === 'deduction' ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                    {{ $line->side->value === 'deduction' ? '−' : '' }}{{ money((string) $line->amount) }}
                                </dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                            <dt class="text-slate-700 dark:text-slate-200">Gross</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $version->gross_salary) }}</dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ $version->change_reason }}</p>
                </x-ui.card>
            @endforeach
        </div>

        @if ($canCancel)
            @foreach ($versions as $version)
                @continue($version->status->value !== 'scheduled')
                <x-ui.modal :name="'cancel-structure-' . $version->id" title="Withdraw version {{ $version->version }}?" icon="exclamation-triangle">
                    <form method="POST" action="{{ route('admin.salary-structures.cancel', $version) }}" class="space-y-3">
                        @csrf
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            The version it would have replaced becomes open again, so the employee is never left with
                            no structure.
                        </p>
                        <x-ui.form.textarea name="reason" label="Why" rows="2" required />
                        <x-ui.button type="submit" variant="danger" class="w-full">Withdraw version</x-ui.button>
                    </form>
                </x-ui.modal>
            @endforeach
        @endif
    @endif
@endsection
