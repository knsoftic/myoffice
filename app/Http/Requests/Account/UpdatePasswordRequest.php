<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Services\Auth\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing your own password (routes `account.password.update` and Breeze's `password.update`,
 * phase-01 §7).
 *
 * The current password is always required — knowing the session is not enough to replace the
 * credential. Strength rules come from App\Services\Auth\PasswordPolicy, and the new password
 * must differ from the current one.
 */
final class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => array_merge(['confirmed', 'different:current_password'], PasswordPolicy::rules()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That is not your current password.',
            'password.different' => 'Choose a password you have not used here before.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
        ];
    }

    /**
     * The validated new password, in plain text, for the service that hashes it.
     */
    public function newPassword(): string
    {
        return (string) $this->validated('password');
    }
}
