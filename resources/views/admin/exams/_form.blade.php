{{--
    The one exam form, shared by create and edit (phase-19-23 §8, §7.3).

    **Three fields disable themselves once results are published**, and a fourth is never editable
    here at all. `total_marks`, `passing_marks` and `grade_scale_id` are what a whole class was
    measured against, and a result card that was printed and handed over has to stay reproducible — so
    the model refuses to move them, and this greys them out rather than offering an input that would
    throw on save.

    `scheduled_date` is the fourth frozen column, but it is read-only on **every** edit, published or
    not: `ExamService::update()` does not write it. Moving an exam takes a reason and re-runs the clash
    check, so it has its own entry point on the exam's page.

    **The batch is fixed after creation.** An exam's results snapshot that batch's roster on that date;
    moving the exam to another batch would leave marks belonging to students who never sat it.
--}}
@php($editing = isset($exam))
@php($frozen = $frozen ?? [])
@php($isFrozen = fn (string $field): bool => in_array($field, $frozen, true))
@php($lockedNote = 'Locked: results have been published. Withdraw them with a reason to change this.')

<div class="grid gap-6">
    <x-ui.card>
        <x-ui.section-heading title="The paper" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.select name="batch_id" label="Batch" required :disabled="$editing"
                              help="{{ $editing ? 'An exam stays with its batch — its results are that roster.' : 'The course and branch are taken from the batch.' }}">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}"
                            @selected((int) old('batch_id', $editing ? $exam->batch_id : 0) === (int) $batch->id)>
                        {{ $batch->code }} — {{ $batch->course?->name }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="exam_type" label="Kind" required>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}"
                            @selected(old('exam_type', $editing ? $exam->exam_type->value : 'midterm') === $type->value)>
                        {{ $type->label() }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <div class="sm:col-span-2">
                <x-ui.form.input name="name" label="Name" required
                                 :value="old('name', $editing ? $exam->name : '')"
                                 placeholder="Midterm — HTML and CSS" />
            </div>

            <x-ui.form.select name="teacher_id" label="Examiner" placeholder="Nobody named yet"
                              help="Named here so a double booking is caught against their other classes.">
                @foreach ($teachers as $teacher)
                    <option value="{{ $teacher->id }}"
                            @selected((int) old('teacher_id', $editing ? $exam->teacher_id : 0) === (int) $teacher->id)>
                        {{ $teacher->name }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="classroom_id" label="Room" placeholder="No room yet">
                @foreach ($classrooms as $classroom)
                    <option value="{{ $classroom->id }}"
                            @selected((int) old('classroom_id', $editing ? $exam->classroom_id : 0) === (int) $classroom->id)>
                        {{ $classroom->code }} — {{ $classroom->name }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <div class="sm:col-span-2">
                <x-ui.form.textarea name="instructions" label="Instructions" rows="3"
                                    :value="old('instructions', $editing ? $exam->instructions : '')"
                                    help="What to bring, what is allowed, anything the class needs before the day." />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-heading title="When and where"
                              description="Every path that gives an exam a time re-runs the clash check against classes, demos and other exams." />

        <div class="grid gap-4 sm:grid-cols-4">
            {{-- Read-only on edit, always. `ExamService::update()` does not write `scheduled_date`
                 at all: moving an exam takes a reason and re-runs the clash check, so it has its own
                 entry point on the exam's own page. An editable box here would accept a new date,
                 report "Exam updated" and change nothing — which is worse than not offering it. --}}
            <x-ui.form.input type="date" name="scheduled_date" label="Date" required
                             :disabled="$editing"
                             :value="old('scheduled_date', $editing ? app_input_date($exam->scheduled_date) : '')"
                             help="{{ $editing ? 'Use “Move it” on the exam’s page — a date change is recorded with a reason.' : '' }}" />

            <x-ui.form.input type="time" name="start_time" label="Starts"
                             :value="old('start_time', $editing ? app_time($exam->start_time, 'H:i') : '')"
                             help="Leave both times empty for an exam that books no slot." />

            <x-ui.form.input type="time" name="end_time" label="Ends"
                             :value="old('end_time', $editing ? app_time($exam->end_time, 'H:i') : '')" />

            <x-ui.form.input type="number" name="duration_minutes" label="Minutes" min="1" max="1440"
                             :value="old('duration_minutes', $editing ? $exam->duration_minutes : '')"
                             help="Used when no end time is given." />

            <x-ui.form.select name="delivery_mode" label="How it is sat">
                @foreach (App\Enums\DeliveryMode::cases() as $mode)
                    <option value="{{ $mode->value }}"
                            @selected(old('delivery_mode', $editing ? $exam->delivery_mode->value : 'physical') === $mode->value)>
                        {{ $mode->label() }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <div class="sm:col-span-3">
                <x-ui.form.input type="url" name="meeting_url" label="Joining link"
                                 :value="old('meeting_url', $editing ? $exam->meeting_url : '')"
                                 placeholder="https://…"
                                 help="Required for an online exam, and refused for one sat in a room." />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-heading title="Marks and grading" />

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.form.input type="number" step="0.01" min="0.01" name="total_marks" label="Out of" required
                             :disabled="$isFrozen('total_marks')"
                             :value="old('total_marks', $editing ? $exam->total_marks : '100')"
                             help="{{ $isFrozen('total_marks') ? $lockedNote : 'Every mark on the sheet is checked against this.' }}" />

            <x-ui.form.input type="number" step="0.01" min="0" name="passing_marks" label="Pass mark" required
                             :disabled="$isFrozen('passing_marks')"
                             :value="old('passing_marks', $editing ? $exam->passing_marks : '40')"
                             help="{{ $isFrozen('passing_marks') ? $lockedNote : 'Zero means the scale decides who passed.' }}" />

            <x-ui.form.input type="number" step="0.0001" min="0" max="100" name="weight_percentage" label="Weight" suffix="%"
                             :value="old('weight_percentage', $editing ? $exam->weight_percentage : '')"
                             help="Its share of the course's overall grade. Empty weights it equally with its peers." />

            <div class="sm:col-span-2">
                <x-ui.form.select name="grade_scale_id" label="Grade scale"
                                  placeholder="Use the institute's default"
                                  :disabled="$isFrozen('grade_scale_id')"
                                  help="{{ $isFrozen('grade_scale_id') ? $lockedNote : 'The ladder that turns a percentage into a letter.' }}">
                    @foreach ($scales as $option)
                        <option value="{{ $option->id }}"
                                @selected((int) old('grade_scale_id', $editing ? $exam->grade_scale_id : 0) === (int) $option->id)>
                            {{ $option->code }} — {{ $option->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>
            </div>

            <x-ui.form.input name="notes" label="Internal note"
                             :value="old('notes', $editing ? $exam->notes : '')"
                             help="Staff only. Never shown to a student." />
        </div>
    </x-ui.card>
</div>
