@extends('layouts.admin')

@section('title', 'New payroll run')

@section('header')
    <x-ui.page-header title="New payroll run" subtitle="Everything that decides the figures is snapshotted when the run opens." icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payroll-runs.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.payroll-runs.store') }}" class="max-w-xl space-y-4">
        @csrf

        <x-ui.card title="The period">
            <div class="space-y-3">
                <x-ui.form.input type="month" name="period" label="Month" required :value="old('period', $defaultMonth)"
                    help="One live regular run per branch per month. A missed figure becomes a correction run, never a second regular one." />
                <x-ui.form.input name="title" label="Title" maxlength="150" :value="old('title')"
                    help="Optional — defaults to the month." />
                <x-ui.form.input type="date" name="payment_date" label="Planned payment date" :value="old('payment_date')" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                The run stores the day basis, the loss-of-pay basis and the tax mode as they are today. A run
                generated in April therefore produces the same figures in June, even if somebody changes a default
                in May.
            </p>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.payroll-runs.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Open the run</x-ui.button>
        </div>
    </form>
@endsection
