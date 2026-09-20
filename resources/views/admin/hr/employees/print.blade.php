@extends('layouts.admin')

@section('title', $employee->name . ' — profile')

@section('header')
    <x-ui.page-header :title="$employee->name" :subtitle="$employee->employee_code" icon="printer">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.employees.show', $employee)">Back</x-ui.button>
            <x-ui.button onclick="window.print()" icon="printer">Print this page</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card>
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $employee->name }}</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ $employee->employee_code }} · {{ $employee->designation?->title ?? 'No title' }}
            · {{ $employee->department?->name ?? 'No department' }}
        </p>

        <dl class="mt-6 grid gap-x-8 gap-y-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Joined</dt>
                <dd class="text-sm text-slate-900 dark:text-white">{{ app_date($employee->joining_date) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Employment</dt>
                <dd class="text-sm text-slate-900 dark:text-white">{{ $employee->employment_type->label() }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Email</dt>
                <dd class="text-sm text-slate-900 dark:text-white">{{ $employee->email ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Phone</dt>
                <dd class="text-sm text-slate-900 dark:text-white">{{ $employee->phone ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Address</dt>
                <dd class="text-sm text-slate-900 dark:text-white">{{ $employee->address ?? '—' }}{{ $employee->city ? ', '.$employee->city : '' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">In an emergency</dt>
                <dd class="text-sm text-slate-900 dark:text-white">
                    {{ $employee->emergency_contact_name ?? '—' }}
                    @if ($employee->emergency_contact_phone) · {{ $employee->emergency_contact_phone }} @endif
                </dd>
            </div>
        </dl>

        @if ($employee->skills->isNotEmpty())
            <h3 class="mt-6 text-sm font-semibold text-slate-900 dark:text-white">Skills</h3>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($employee->skills as $skill)
                    <li><x-ui.badge size="xs">{{ $skill->name }}</x-ui.badge></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
@endsection
