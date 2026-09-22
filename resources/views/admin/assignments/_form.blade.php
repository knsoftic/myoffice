{{--
    The one assignment form, shared by create and edit (phase-19-23 §8.5).

    **The three frozen fields disable themselves once anything has been graded.** `total_marks`,
    `deadline_at` and `submission_type` are what a whole class was judged against; the model refuses to
    move them, so offering an editable input that would fail on save is worse than saying why.
--}}
@php($editing = isset($assignment))
@php($frozen = $frozen ?? [])
@php($isFrozen = fn (string $field): bool => in_array($field, $frozen, true))

<div class="grid gap-6">
    <x-ui.card>
        <x-ui.section-heading title="The work" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.select name="batch_id" label="Batch" required :disabled="$editing"
                              help="{{ $editing ? 'An assignment stays with its batch. Duplicate it to set the same work elsewhere.' : 'One batch. Duplicate it afterwards for the others.' }}">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}"
                            @selected((int) old('batch_id', $editing ? $assignment->batch_id : 0) === (int) $batch->id)>
                        {{ $batch->code }} — {{ $batch->course?->name }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="submission_type" label="What they hand in"
                              :disabled="$isFrozen('submission_type')"
                              help="{{ $isFrozen('submission_type') ? 'Locked: work has already been marked against this.' : '' }}">
                @foreach ($submissionTypes as $type)
                    <option value="{{ $type->value }}"
                            @selected(old('submission_type', $editing ? $assignment->submission_type->value : 'file_or_text') === $type->value)>
                        {{ $type->label() }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <div class="sm:col-span-2">
                <x-ui.form.input name="title" label="Title" required
                                 :value="old('title', $editing ? $assignment->title : '')"
                                 placeholder="Lab 1 — a responsive layout" />
            </div>

            <div class="sm:col-span-2">
                <x-ui.form.textarea name="description" label="The brief" rows="4"
                                    :value="old('description', $editing ? $assignment->description : '')" />
            </div>

            <div class="sm:col-span-2">
                <x-ui.form.textarea name="instructions" label="Instructions" rows="3"
                                    :value="old('instructions', $editing ? $assignment->instructions : '')"
                                    help="How to hand it in, what to name the file, anything procedural." />
            </div>

            <div class="sm:col-span-2">
                <x-ui.form.file name="brief" label="Attach a brief"
                                :hint="App\DataObjects\Files\FileRules::assignmentBrief()->describe()"
                                :current="$editing ? $assignment->attachment_original_name : null" />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-heading title="Marks" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="total_marks" label="Out of" type="number" step="0.01" min="0.01" required
                             :value="old('total_marks', $editing ? $assignment->total_marks : '100')"
                             :disabled="$isFrozen('total_marks')"
                             help="{{ $isFrozen('total_marks') ? 'Locked: this is what a class has already been marked out of.' : '' }}" />

            <x-ui.form.input name="passing_marks" label="Pass mark" type="number" step="0.01" min="0"
                             :value="old('passing_marks', $editing ? $assignment->passing_marks : '')"
                             help="Leave empty for marks with no pass line." />
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-heading title="Deadline and lateness"
                              subtitle="A deadline is when work becomes late. A cutoff is when it stops being accepted. They are different, which is why there are two." />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="assigned_on" label="Set on" type="date"
                             :value="old('assigned_on', app_input_date($editing ? $assignment->assigned_on : now()))" />

            <x-ui.form.input name="deadline_at" label="Deadline" type="datetime-local" required
                             :value="old('deadline_at', $editing ? app_input_datetime($assignment->deadline_at) : '')"
                             :disabled="$isFrozen('deadline_at')"
                             help="{{ $isFrozen('deadline_at') ? 'Locked: lateness has already been decided against this.' : '' }}" />

            <div class="sm:col-span-2">
                <x-ui.form.toggle name="late_submission_allowed" label="Accept late work"
                                  description="Late work is still recorded as late — this decides whether it is accepted at all."
                                  :checked="(bool) old('late_submission_allowed', $editing ? $assignment->late_submission_allowed : true)" />
            </div>

            <x-ui.form.input name="late_cutoff_at" label="Final cutoff" type="datetime-local"
                             :value="old('late_cutoff_at', $editing ? app_input_datetime($assignment->late_cutoff_at) : '')"
                             help="Empty means no hard stop while late work is accepted." />

            <x-ui.form.input name="late_penalty_percentage" label="Late penalty" type="number" step="0.0001" min="0" max="100"
                             suffix="%"
                             :value="old('late_penalty_percentage', $editing ? $assignment->late_penalty_percentage : '0')"
                             help="Percent of the total, deducted once. It can never take a mark below zero." />
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-heading title="Attempts and release" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="max_files" label="Files per submission" type="number" min="1" max="10"
                             :value="old('max_files', $editing ? $assignment->max_files : 3)" />

            <x-ui.form.input name="max_attempts" label="Attempts allowed" type="number" min="1" max="10"
                             :value="old('max_attempts', $editing ? $assignment->max_attempts : 3)"
                             help="Each attempt keeps its own files and marks; a new one supersedes the last." />

            <div class="sm:col-span-2">
                <x-ui.form.toggle name="allow_resubmission" label="Let students try again"
                                  :checked="(bool) old('allow_resubmission', $editing ? $assignment->allow_resubmission : true)" />
            </div>

            <div class="sm:col-span-2">
                <x-ui.form.toggle name="marks_visible_to_students" label="Show marks as they are entered"
                                  description="Turn this off to mark the whole batch privately and release every mark in one go."
                                  :checked="(bool) old('marks_visible_to_students', $editing ? $assignment->marks_visible_to_students : true)" />
            </div>
        </div>
    </x-ui.card>

    @if ($frozen !== [])
        <x-ui.card>
            <x-ui.section-heading title="Why is this changing?" />

            <x-ui.form.input name="reason" label="Reason" required
                             help="Marks have already been given against this assignment. A change to what it is out of, when it was due, or what may be handed in is recorded with your name against it." />
        </x-ui.card>
    @endif

    <x-ui.card>
        <x-ui.form.textarea name="notes" label="Internal notes" rows="2"
                            :value="old('notes', $editing ? $assignment->notes : '')"
                            help="Never shown to students." />
    </x-ui.card>
</div>
