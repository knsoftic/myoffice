<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Choose a new password" after following a reset link (route `password.store`).
 *
 * The strength rules are the same ones the account change-password screen enforces — they come
 * from App\Services\Auth\PasswordPolicy, so a reset can never slip a weaker password in.
 */
final class NewPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => array_merge(['confirmed'], PasswordPolicy::rules()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => 'new password',
        ];
    }
}
