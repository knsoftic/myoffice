<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A student record, created or edited by staff (§66, phase-14-17 §7.4).
 *
 * **`student_code`, `registration_number`, `status` and the referral columns are not here.** The two
 * numbers are issued by `StudentNumberService`, the status moves through `StudentService::changeStatus()`
 * and §2.30.4, and the referral snapshot is written by Phase 9's listener. A form field for any of
 * them would be a second writer for a fact that already has one.
 *
 * The CNIC is validated as digits after punctuation is stripped, so a receptionist may type it either
 * way and the column still holds the one form `chk_st_cnic_digits` allows.
 */
final class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cnic')) {
            $this->merge(['cnic' => preg_replace('/\D/', '', (string) $this->input('cnic')) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $student = $this->route('student');
        $id = is_object($student) ? $student->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            // 13 digits, the length of a CNIC and a B-Form alike. Unique so two records for one person
            // are refused at the door rather than merged later.
            'cnic' => ['nullable', 'digits:13', Rule::unique('students', 'cnic')->ignore($id)->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:180', Rule::unique('students', 'email')->ignore($id)->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'guardian_relation' => ['nullable', 'string', 'max:40'],
            'education' => ['nullable', 'string', 'max:150'],
            'institution_name' => ['nullable', 'string', 'max:180'],
            'joining_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Only on create, and only these two: a record made from an enquiry starts at `inquiry`,
            // everything else starts at `applied`. Every later move is a status change with its own rules.
            'status' => ['nullable', Rule::in([StudentStatus::Inquiry->value, StudentStatus::Applied->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cnic.digits' => 'A CNIC is 13 digits. Dashes are fine to type — they are stripped before it is stored.',
            'cnic.unique' => 'Another student already has that CNIC. Open their record rather than creating a second one.',
            'phone.required' => 'A student record needs a phone number.',
        ];
    }
}
