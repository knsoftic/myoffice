<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Models\Institute\StudentIdCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Issuing a student ID card (phase-19-23 §6.15, §8, requirement §85).
 *
 * **Four settable things, for the same reason a certificate has four.** Every printed field is a
 * snapshot the service copies at issue time (INV-21-4) — a request that could set
 * `student_name_snapshot` could print somebody else's name on a card, and one that could set
 * `card_number` could collide with a card already in a wallet.
 *
 * **`valid_until` is settable but rarely sent.** The service computes it from
 * `institute.id_card_validity_months`, and a present-but-null value clears it — which is a real
 * choice (a card with no expiry) rather than a missing one, so the rule is `nullable` and the service
 * distinguishes absent from null.
 */
class IssueIdCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentIdCard::class) === true;
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
            // Absent means "work it out from the settings"; present-and-null means "no expiry".
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $validUntil = $this->input('valid_until');

            // `chk_sic_valid` says the same at the database. This says it on the field, before
            // somebody has filled in the rest of the form.
            if (is_string($validUntil) && trim($validUntil) !== '' && strtotime($validUntil) < strtotime('today')) {
                $validator->errors()->add(
                    'valid_until',
                    'A card cannot be issued already expired. Leave this empty to use the institute’s '
                    .'validity period.',
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
            'student_batch_enrollment_id.required' => 'A card is issued against one enrolment.',
            'student_batch_enrollment_id.exists' => 'That enrolment no longer exists.',
        ];
    }
}
