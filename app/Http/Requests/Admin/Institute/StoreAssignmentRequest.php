<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\DataObjects\Files\FileRules;
use App\Enums\SubmissionType;
use App\Models\Institute\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Setting or editing work (phase-19-23 §8.5, requirement §80).
 *
 * **`passing_marks <= total_marks` and `late_cutoff_at >= deadline_at` are checked here *and* by
 * `chk_as_pass` and `chk_as_cutoff`.** The database guarantee is what makes them true; this is what
 * makes the teacher see a message on the field instead of a 500 from a constraint they cannot read.
 *
 * **The three frozen columns are not special here.** `total_marks`, `deadline_at` and `submission_type`
 * are ordinary fields at this layer; the model refuses to move them once anything is graded, because
 * `Gate::before` would wave a Super Admin past any check a request or a policy made.
 */
class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof Assignment
            ? $this->user()?->can('update', $assignment) === true
            : $this->user()?->can('create', Assignment::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->route('assignment') instanceof Assignment;

        return [
            // An assignment is for one batch and stays with it — a course-wide assignment is several
            // rows, which is what `duplicateToBatches()` is for.
            'batch_id' => [$editing ? 'nullable' : 'required', 'integer', Rule::exists('batches', 'id')->withoutTrashed()],
            'course_topic_assignment_id' => ['nullable', 'integer', Rule::exists('course_topic_assignments', 'id')->withoutTrashed()],
            'course_topic_id' => ['nullable', 'integer', Rule::exists('course_topics', 'id')->withoutTrashed()],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->withoutTrashed()],

            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:20000'],
            'instructions' => ['nullable', 'string', 'max:5000'],

            // `decimal:0,2` rather than bare `numeric`: `numeric` accepts `1E1`, which no decimal(8,2)
            // column can parse. The same omission cost a settings field in Phase 18 (D114).
            'total_marks' => [$editing ? 'nullable' : 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999'],
            'passing_marks' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],

            'submission_type' => ['nullable', Rule::enum(SubmissionType::class)],
            'allowed_extensions' => ['nullable', 'array', 'max:30'],
            'allowed_extensions.*' => ['string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'max_file_size_mb' => ['nullable', 'integer', 'min:1', 'max:512'],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:10'],

            'assigned_on' => ['nullable', 'date'],
            'deadline_at' => [$editing ? 'nullable' : 'required', 'date'],

            'late_submission_allowed' => ['nullable', 'boolean'],
            'late_cutoff_at' => ['nullable', 'date'],
            'late_penalty_percentage' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],

            'allow_resubmission' => ['nullable', 'boolean'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'marks_visible_to_students' => ['nullable', 'boolean'],

            'notes' => ['nullable', 'string', 'max:500'],

            'brief' => ['nullable', 'file', 'max:'.FileRules::assignmentBrief()->maxKilobytes()],

            // Required by the model once a graded submission exists; the controller passes it through
            // to `amend()`, which is the only path allowed to move the three frozen columns.
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $total = $this->input('total_marks');
            $passing = $this->input('passing_marks');

            if ($total !== null && $passing !== null && $passing !== ''
                && bccomp((string) $passing, (string) $total, 2) > 0) {
                $validator->errors()->add(
                    'passing_marks',
                    'The pass mark cannot be higher than what the assignment is out of.',
                );
            }

            $deadline = $this->input('deadline_at');
            $cutoff = $this->input('late_cutoff_at');

            if ($deadline && $cutoff && strtotime((string) $cutoff) < strtotime((string) $deadline)) {
                $validator->errors()->add(
                    'late_cutoff_at',
                    'The final cutoff cannot be before the deadline — it would close submissions while the assignment still said it was open.',
                );
            }

            // A penalty that is never charged is a setting nobody reads. Said here rather than
            // refused, because 0% with lateness allowed is a legitimate "record it but do not
            // charge for it" choice.
            if ((bool) $this->input('late_submission_allowed', true) === false
                && $cutoff !== null && $cutoff !== '') {
                $validator->errors()->add(
                    'late_cutoff_at',
                    'There is no cutoff to set when late work is not accepted at all.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'total_marks.gt' => 'An assignment has to be worth something.',
            'total_marks.decimal' => 'Marks are a plain number like 50 or 12.50.',
            'late_penalty_percentage.decimal' => 'A penalty is a plain percentage like 10 or 12.5.',
            'allowed_extensions.*.regex' => 'An extension is letters and numbers only, with no dot.',
            'reason.min' => 'Say a little more about why this is changing.',
        ];
    }
}
