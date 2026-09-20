@extends('layouts.admin')

@section('title', 'New advance')

@section('header')
    <x-ui.page-header title="New advance" subtitle="An amount above the usual ceiling needs somebody who can approve one." icon="credit-card">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.advances.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.advances.store') }}" class="max-w-2xl space-y-4">
        @csrf

        <x-ui.card title="The request">
            <div class="space-y-3">
                <x-ui.form.select name="employee_id" label="Employee" required placeholder="Choose somebody">
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected((int) old('employee_id') === $employee->id)>
                            {{ $employee->name }} ({{ $employee->employee_code }})
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input type="number" step="0.01" min="0.01" name="amount" label="Amount" required :value="old('amount')" />

                <x-ui.form.input type="number" min="1" max="36" name="installment_count" label="Recover over (months)"
                    required :value="old('installment_count', 1)"
                    help="The installment is the amount divided by this; the residual lands on the last one." />

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.form.input type="number" name="first_recovery_year" label="First recovery year"
                        :value="old('first_recovery_year', now()->addMonthNoOverflow()->year)" />
                    <x-ui.form.input type="number" min="1" max="12" name="first_recovery_month" label="First recovery month"
                        :value="old('first_recovery_month', now()->addMonthNoOverflow()->month)" />
                </div>

                <x-ui.form.textarea name="reason" label="What it is for" rows="3" required :value="old('reason')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.advances.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">File the request</x-ui.button>
        </div>
    </form>
@endsection
