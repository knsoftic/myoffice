<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\DataObjects\Files\FileRules;
use App\Models\Institute\AssignmentSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Marking one submission (phase-19-23 §8.7, INV-19-6's first layer).
 *
 * **The ceiling is checked against the row's *snapshotted* total, not the assignment's current one.**
 * That is the whole reason `assignment_submissions.total_marks` exists: amending an assignment's total
 * must never retroactively make an existing mark invalid. The service checks the same thing against the
 * same column, and `chk_asub_marks` compares the two columns of the row — three layers, and all three
 * are asking about the same number.
 */
class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $submission = $this->route('submission');

        return $submission instanceof AssignmentSubmission
            && $this->user()?->can('update', $submission) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `decimal:0,2` and not bare `numeric` — `numeric` accepts `1E1` (D114).
            'obtained_marks' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'feedback_file' => ['nullable', 'file', 'max:'.FileRules::feedback()->maxKilobytes()],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $submission = $this->route('submission');

            if (! $submission instanceof AssignmentSubmission) {
                return;
            }

            $total = (string) $submission->getAttribute('total_marks');
            $marks = $this->input('obtained_marks');

            if ($marks !== null && $marks !== '' && bccomp((string) $marks, $total, 2) > 0) {
                $validator->errors()->add('obtained_marks', sprintf(
                    'This assignment is out of %s, so %s is not a possible mark.',
                    $total,
                    (string) $marks,
                ));
            }

            // A mark the student has already seen does not move without a reason. Asked here so the
            // teacher is told before they type the mark, and asserted again by the service.
            if ($submission->getAttribute('marks_released_at') !== null
                && trim((string) $this->input('reason')) === '') {
                $validator->errors()->add(
                    'reason',
                    'This student has already seen their mark. Say why it is changing.',
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
            'obtained_marks.required' => 'Enter a mark.',
            'obtained_marks.decimal' => 'A mark is a plain number like 38 or 38.50.',
            'reason.min' => 'Say a little more about why the mark is changing.',
        ];
    }
}
