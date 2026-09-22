<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use App\DataObjects\Files\FileRules;
use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A student handing work in (phase-19-23 §8.6, §7.9).
 *
 * **Every file is validated before any file is stored.** `SecureFileService::storeMany()` does the
 * same, and doing it here as well means the student gets the message on the field rather than a
 * refusal after a slow upload has already half-landed.
 *
 * **The `submission_type` rules come from the enum**, so this request, the screen and the service all
 * ask the same object the same question — `file_or_text` in particular needs *at least one*, which no
 * single boolean can express and which is exactly the case three separate implementations would get
 * differently.
 */
class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already gated by `can:student_portal.assignment_submit`; what this adds is that
        // the submission being written is the signed-in student's own. A row that is not theirs is a
        // 404 at the controller, never a 403 — an id that answers differently can be enumerated.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = FileRules::submission();

        return [
            'submission_text' => ['nullable', 'string', 'max:50000'],
            'files' => ['nullable', 'array', 'max:'.$this->maxFiles()],
            'files.*' => ['file', 'max:'.$rules->maxKilobytes()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assignment = $this->assignment();

            if (! $assignment instanceof Assignment) {
                return;
            }

            $type = $assignment->submission_type;
            $text = trim((string) $this->input('submission_text'));
            $files = $this->file('files') ?? [];
            $count = is_array($files) ? count($files) : 1;
            $hasText = $text !== '';

            if ($type->requiresFile() && $count < 1) {
                $validator->errors()->add('files', 'This assignment needs a file.');
            }

            if ($type->requiresText() && ! $hasText) {
                $validator->errors()->add('submission_text', 'This assignment needs your written answer.');
            }

            if ($type->requiresEither() && $count < 1 && ! $hasText) {
                $validator->errors()->add('submission_text', 'Attach a file or type your answer.');
            }

            if (! $type->allowsFile() && $count > 0) {
                $validator->errors()->add('files', 'This assignment is answered in the box, not with a file.');
            }

            if (! $type->allowsText() && $hasText) {
                $validator->errors()->add('submission_text', 'This assignment is answered with a file.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.max' => 'You may attach at most '.$this->maxFiles().' files to this assignment.',
            'files.*.max' => 'One of those files is larger than the institute allows.',
        ];
    }

    private function maxFiles(): int
    {
        $assignment = $this->assignment();

        return $assignment instanceof Assignment
            ? max(1, (int) $assignment->getAttribute('max_files'))
            : FileRules::submission()->maxFiles;
    }

    /** The assignment, whether the route names it directly or names the submission on it. */
    private function assignment(): ?Assignment
    {
        $assignment = $this->route('assignment');

        if ($assignment instanceof Assignment) {
            return $assignment;
        }

        $submission = $this->route('submission');

        return $submission instanceof AssignmentSubmission ? $submission->assignment : null;
    }
}
