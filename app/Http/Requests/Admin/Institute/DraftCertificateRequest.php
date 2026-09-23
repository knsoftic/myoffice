<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Models\Institute\Certificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Preparing a certificate (phase-19-23 §6.14, §8, requirement §84).
 *
 * **Almost nothing here is settable, and that is the shape of the phase.** A certificate's printed
 * fields are snapshots the service copies from the student, the course, the batch and the exam
 * results at the moment it is drafted (INV-21-4). A request that could set `student_name_snapshot`
 * could make a certificate claim somebody else completed the course; one that could set `grade` when
 * the institute computes grades could put a mark on paper that no exam produced.
 *
 * So the writable surface is four things: which enrolment, which template, when they finished, and a
 * note. The grade joins that list **only** when `institute.certificate_grade_source` is `manual` —
 * checked below, because a Form Request that accepted it unconditionally would let a request override
 * a computed grade by sending one.
 */
class DraftCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Certificate::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_batch_enrollment_id' => [
                'required', 'integer',
                Rule::exists('student_batch_enrollments', 'id')->withoutTrashed(),
            ],
            'print_template_id' => [
                'nullable', 'integer',
                Rule::exists('print_templates', 'id')->withoutTrashed(),
            ],
            'grade_scale_id' => ['nullable', 'integer', Rule::exists('grade_scales', 'id')->withoutTrashed()],

            // Defaults to today in the service. `chk_ce_dates` refuses one before the course started.
            'completion_date' => ['nullable', 'date'],

            // Only honoured when the grade source is `manual`; see withValidator().
            'grade' => ['nullable', 'string', 'max:8'],
            'grade_point' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10'],
            // A percentage, so decimal(8,4) — CLAUDE.md §3, and `decimal` is what refuses `1E1` (D114).
            'percentage' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $manual = setting('institute.certificate_grade_source', 'weighted_average') === 'manual';

            if ($manual) {
                // The institute has said a person types the grade, so one is expected.
                if (trim((string) $this->input('grade')) === '') {
                    $validator->errors()->add(
                        'grade',
                        'This institute types certificate grades by hand, so this one needs a grade.',
                    );
                }

                return;
            }

            // **Refused rather than ignored.** Silently dropping a submitted grade would let a screen
            // show a field, accept a value, and print something else — which is the D121 shape. If a
            // request is sending one, either the form is wrong or somebody is trying it.
            foreach (['grade', 'grade_point', 'percentage'] as $field) {
                if (trim((string) $this->input($field)) !== '') {
                    $validator->errors()->add(
                        $field,
                        'This institute computes certificate grades from exam results, so this cannot be '
                        .'set by hand. Change the grade source in the institute settings to type one.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_batch_enrollment_id.required' => 'A certificate is issued against one enrolment.',
            'student_batch_enrollment_id.exists' => 'That enrolment no longer exists.',
            'grade_point.decimal' => 'Grade points are a plain number like 4 or 3.70.',
            'percentage.decimal' => 'A percentage is a plain number like 87 or 87.25.',
        ];
    }
}
