<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Http\Requests\Admin\Concerns\ValidatesRoleAssignment;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create a user account.
 *
 * Every writable field is declared here; the controller never reads `$request->all()`.
 * Authorization is checked in `authorize()` as well as in the controller so a forbidden request
 * answers 403 instead of leaking which fields failed validation.
 */
final class StoreUserRequest extends FormRequest
{
    use ValidatesRoleAssignment;

    /** Accepts digits plus the separators people actually type. */
    public const PHONE_PATTERN = '/^[0-9+()\-.\s]{7,32}$/';

    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],

            // The `users.email` unique index covers soft-deleted rows too, so the check must
            // not exclude them — a trashed account still owns its address.
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],

            'password' => ['required', 'string', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32', 'regex:'.self::PHONE_PATTERN],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:'.self::PHONE_PATTERN],

            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            'status' => ['required', Rule::enum(UserStatus::class)],
            'status_reason' => ['nullable', 'string', 'max:255'],

            'must_change_password' => ['nullable', 'boolean'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],

            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Choose at least one role — an account with no role cannot reach any panel.',
            'roles.min' => 'Choose at least one role — an account with no role cannot reach any panel.',
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
            'whatsapp.regex' => 'Use digits, spaces and + ( ) - only.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_id' => 'branch',
            'must_change_password' => 'force password change',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'must_change_password' => $this->boolean('must_change_password'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'branch_id' => $this->filled('branch_id') ? $this->input('branch_id') : null,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Rank rule through UserPolicy::assignRoles(). The target is an unsaved instance on
            // purpose: the account does not exist yet, so it has no rank of its own and the rule
            // reduces to "you can only hand out roles weaker than your own".
            $this->assertRolesAreGrantable($validator, $this->submittedRoleIds(), new User);
        });
    }

    /**
     * The payload the service consumes: no `_token`, no `password_confirmation`, no file.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->except(['avatar', 'password_confirmation']);

        $data['must_change_password'] = (bool) ($data['must_change_password'] ?? false);
        $data['roles'] = array_values(array_map('intval', $data['roles'] ?? []));

        if (($data['status'] ?? null) === UserStatus::Active->value) {
            $data['status_reason'] = null;
        }

        return $data;
    }
}
