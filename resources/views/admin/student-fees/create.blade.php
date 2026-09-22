@extends('layouts.admin')

@section('title', 'New fee charge')

@section('header')
    <x-ui.page-header title="New fee charge"
                      subtitle="One head, one amount. A full set for an admission is the fee-structure wizard's job, not this form's."
                      icon="banknotes"
                      :back="route('admin.student-fees.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.student-fees.store') }}" class="max-w-3xl space-y-4">
        @csrf

        <x-ui.card title="Who and what">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="student_id" label="Student id" type="number" min="1" required
                                 :value="old('student_id', request('student_id'))"
                                 help="Raised against a student, not an admission — an exam or certificate fee often has no admission behind it." />

                <x-ui.form.select name="fee_type" label="Head" required>
                    @foreach ($feeTypes as $type)
                        <option value="{{ $type->value }}" @selected(old('fee_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="title" label="Title" :value="old('title')"
                                 placeholder="Defaults to the head's own name"
                                 help="What the slip prints for this line." />

                <x-ui.form.input name="gross_amount" label="Amount" type="number" step="0.01" min="0.01" required
                                 :value="old('gross_amount')"
                                 help="A charge of zero is refused. If the student owes nothing for this head, raise it and record a discount — so the reason is on the record." />
            </div>
        </x-ui.card>

        <x-ui.card title="Where it belongs" subtitle="All optional, and all display-only: none of them moves money.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="course_id" label="Course" placeholder="Not tied to a course">
                    @foreach ($pickers['courses'] as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('course_id') === (int) $id)>{{ $name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="batch_id" label="Batch" placeholder="Not tied to a batch">
                    @foreach ($pickers['batches'] as $id => $code)
                        <option value="{{ $id }}" @selected((int) old('batch_id') === (int) $id)>{{ $code }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="student_admission_id" label="Admission id" type="number" min="1"
                                 :value="old('student_admission_id')"
                                 help="Links the charge into the admission's totals. Must belong to the same student." />

                <x-ui.form.input name="due_date" label="Due date" type="date" :value="old('due_date')"
                                 :help="'Defaults to '.app_number($dueDays).' days from today.'" />
            </div>

            <x-ui.form.textarea name="notes" label="Notes" rows="2" class="mt-4" :value="old('notes')" />
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.student-fees.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" icon="plus">Raise the charge</x-ui.button>
        </div>
    </form>
@endsection
