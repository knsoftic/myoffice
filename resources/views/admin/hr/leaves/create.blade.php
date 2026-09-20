@extends('layouts.admin')

@section('title', 'Apply for leave')

@section('header')
    <x-ui.page-header title="Apply for leave" subtitle="Weekends and holidays inside the range are kept, marked not counted." icon="calendar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.leaves.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.leaves.store') }}" class="max-w-2xl space-y-4">
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

                <x-ui.form.select name="leave_type_id" label="Leave type" required placeholder="Choose a type">
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected((int) old('leave_type_id') === $type->id)>
                            {{ $type->name }} — {{ app_number((float) $type->annual_quota_days, 2) }} day quota
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.form.input type="date" name="from_date" label="From" required :value="old('from_date')" />
                    <x-ui.form.input type="date" name="to_date" label="To" required :value="old('to_date')" />
                </div>

                <x-ui.form.select name="day_portion" label="Portion" :options="$portions" :selected="old('day_portion', 'full_day')" required
                    help="A half day is a single date; a longer absence is always whole days." />

                <x-ui.form.textarea name="reason" label="Reason" rows="3" required :value="old('reason')" />
                <x-ui.form.input name="contact_during_leave" label="Contact while away" maxlength="64" :value="old('contact_during_leave')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.leaves.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">File the request</x-ui.button>
        </div>
    </form>
@endsection
