<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Step 1 of the two-step registration: the student, and their portal login in the same act.
 *
 * It is `StoreStudentRequest`'s field set plus three: `email` becomes **required** here, and
 * `password` / `password_confirmation` appear.
 *
 * **Why email is required here and nullable there.** The ordinary student form creates a record; this
 * one creates an account, and an account is an e-mail address. A student added through the other
 * screen can be given a login later; a student registered through this one is handed their
 * credentials across the desk before they leave.
 *
 * **On the operator typing a password.** Everywhere else in this system a portal password is
 * generated and mailed — `StudentService::createLogin()` keeps no copy, and
 * `PortalLoginCreatedNotification` is the only thing that ever sees it. Here the institute asked for
 * the opposite, because a receptionist admitting a walk-in reads the credentials out. Two things make
 * that defensible and both are enforced rather than assumed: `must_change_password` is set on the
 * user, so the operator's copy stops being the password at the student's first sign-in, and no
 * credentials e-mail is sent, because putting a password the operator already knows into an inbox and
 * a mail log would add two copies and no security.
 *
 * The password rules are Laravel's `Password::defaults()`, which is what the rest of the application
 * uses for a person choosing their own — a weaker rule here would mean the account a staff member
 * creates is easier to guess than the one a student creates.
 */
final class StoreRegistrationStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries `can:students.create`; one answer to "who may do this", not two.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cnic')) {
            $this->merge(['cnic' => preg_replace('/\D/', '', (string) $this->input('cnic')) ?: null]);
        }

        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Identity
            'name' => ['required', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'cnic' => ['nullable', 'string', 'digits_between:13,15'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],

            // Contact. The e-mail is the login, so it is required and unique against `users` as well
            // as `students` — a second student on one address would be a second account nobody can
            // sign in to.
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => [
                'required', 'email:rfc', 'max:150',
                Rule::unique('students', 'email')->whereNull('deleted_at'),
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],

            // Additional
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'guardian_relation' => ['nullable', 'string', 'max:50'],
            'education' => ['nullable', 'string', 'max:150'],
            'institution_name' => ['nullable', 'string', 'max:150'],
            'joining_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // The login
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'The e-mail is the student\'s username, so it is needed to create the account.',
            'email.unique' => 'That e-mail already belongs to somebody. Two accounts on one address is how a person ends up locked out of their own record.',
            'password.confirmed' => 'The two passwords do not match. Retype both — the student will be told this one.',
        ];
    }

    /**
     * The student columns, without the credential fields.
     *
     * @return array<string, mixed>
     */
    public function studentData(): array
    {
        return array_diff_key($this->validated(), array_flip(['password', 'password_confirmation']));
    }
}
