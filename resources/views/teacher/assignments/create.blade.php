@extends('layouts.panel')

@section('title', 'Set work')

@section('header')
    <x-ui.page-header title="Set work"
                      subtitle="Saved as a draft. Your batch sees nothing until you publish it."
                      icon="clipboard-document">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('teacher.assignments.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('teacher.assignments.store') }}" enctype="multipart/form-data" class="grid gap-6">
        @csrf

        <x-ui.card>
            <x-ui.section-heading title="The work" />

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Only batches this teacher reaches. The controller re-checks the posted id against
                     the same scope, because a picker is a convenience and never a control. --}}
                <x-ui.form.select name="batch_id" label="Batch" required>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" @selected((int) old('batch_id') === (int) $batch->id)>
                            {{ $batch->code }} — {{ $batch->course?->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="submission_type" label="What they hand in">
                    @foreach ($submissionTypes as $type)
                        <option value="{{ $type->value }}" @selected(old('submission_type', 'file_or_text') === $type->value)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div class="sm:col-span-2">
                    <x-ui.form.input name="title" label="Title" required :value="old('title')" />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="description" label="The brief" rows="4" :value="old('description')" />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.file name="brief" label="Attach a brief"
                                    :hint="App\DataObjects\Files\FileRules::assignmentBrief()->describe()" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-ui.section-heading title="Marks and deadline" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="total_marks" label="Out of" type="number" step="0.01" min="0.01" required
                                 :value="old('total_marks', '100')" />

                <x-ui.form.input name="passing_marks" label="Pass mark" type="number" step="0.01" min="0"
                                 :value="old('passing_marks')" help="Leave empty for marks with no pass line." />

                <x-ui.form.input name="deadline_at" label="Deadline" type="datetime-local" required
                                 :value="old('deadline_at')" />

                <x-ui.form.input name="late_penalty_percentage" label="Late penalty" type="number"
                                 step="0.0001" min="0" max="100" suffix="%"
                                 :value="old('late_penalty_percentage', '0')"
                                 help="Percent of the total, charged once. It can never take a mark below zero." />

                <div class="sm:col-span-2">
                    <x-ui.form.toggle name="late_submission_allowed" label="Accept late work"
                                      description="Late work is still recorded as late — this decides whether it is accepted at all."
                                      :checked="(bool) old('late_submission_allowed', true)" />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.toggle name="marks_visible_to_students" label="Show marks as they are entered"
                                      description="Turn this off to mark the whole batch privately and release every mark together."
                                      :checked="(bool) old('marks_visible_to_students', true)" />
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('teacher.assignments.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save as draft</x-ui.button>
        </div>
    </form>
@endsection
