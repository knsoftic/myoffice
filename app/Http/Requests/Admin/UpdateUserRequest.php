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
 * Update a user account.
 *
 * Differences from the store request: the password is optional, the e-mail uniqueness check
 * ignores this row, and only the roles that are actually **changing** are checked against the
 * rank rule — so an admin who is not allowed to hand out a strong role can still edit the phone
 * number of someone who already holds it.
 *
 * Two fields the create form owns are deliberately **not writable here**:
 *
 *   · `status` / `status_reason` — taking access away is `PATCH users/{user}/status`, which demands
 *     `users.change_status` and a written reason, and which refuses your own account. Accepting a
 *     status here let `users.edit` alone suspend anyone weaker with no reason and no audit note,
 *     and let the only Super Admin suspend themselves out of their own system.
 *   · your own `roles` — `UserPolicy::update()` allows editing your own row (name, phone, avatar),
 *     which turned the role checkboxes into self-service. A `Gate` check cannot close that on its
 *     own, because `Gate::before` hands a Super Admin every ability, so the self-check is made
 *     here in plain PHP and again in `UserService`.
 */
final class UpdateUserRequest extends FormRequest
{
    use ValidatesRoleAssignment;

    public function authorize(): bool
    {
        $user = $this->target();

        return $user instanceof User && Gate::allows('update', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->target();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],

            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],

            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32', 'regex:'.StoreUserRequest::PHONE_PATTERN],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:'.StoreUserRequest::PHONE_PATTERN],

            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],

            // `status` and `status_reason` are absent on purpose — see the class docblock. A
            // submitted status is still inspected in withValidator() so a stale or forged form is
            // refused loudly instead of appearing to save.

            'must_change_password' => ['nullable', 'boolean'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],

            // Required for anyone else's account (an account with no role reaches no panel), and
            // optional on your own, where the form renders the role list read-only and submits
            // nothing. Submitting one anyway is rejected below.
            'roles' => [
                Rule::requiredIf(fn (): bool => ! $this->targetIsActor()),
                'array',
                'min:1',
            ],
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
            'remove_avatar' => $this->boolean('remove_avatar'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'branch_id' => $this->filled('branch_id') ? $this->input('branch_id') : null,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->target();

            if (! $user instanceof User) {
                return;
            }

            $this->assertStatusIsUnchanged($validator, $user);
            $this->assertPasswordIsNotSelfServed($validator);

            // `roles` is optional on your own account; a missing key changes nothing.
            if (! $this->has('roles')) {
                return;
            }

            $submitted = $this->submittedRoleIds();
            $current = $user->roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            // Only the delta needs the rank check; leaving an existing grant alone is not a grant.
            $changing = array_values(array_unique(array_merge(
                array_diff($submitted, $current),
                array_diff($current, $submitted),
            )));

            if ($changing === []) {
                return;
            }

            // Checked before the Gate, because `Gate::before` would answer "yes" for a Super Admin.
            if ($this->targetIsActor()) {
                $validator->errors()->add(
                    'roles',
                    'You cannot change your own roles — ask another administrator.',
                );

                return;
            }

            $this->assertRolesAreGrantable($validator, $changing, $user);
        });
    }

    /**
     * Refuse a status that differs from the one on the row.
     *
     * A request that carries the current status (the audit tests and any honest form replay do)
     * passes untouched; anything that would *change* it is sent to the status endpoint, which is
     * the only place holding `users.change_status` and demanding a reason.
     */
    private function assertStatusIsUnchanged(Validator $validator, User $user): void
    {
        if (! $this->has('status')) {
            return;
        }

        $submitted = $this->input('status');
        $submitted = is_string($submitted) ? UserStatus::tryFrom($submitted) : null;

        if ($submitted === null || $submitted === $user->status) {
            return;
        }

        $validator->errors()->add(
            'status',
            'Account status is changed from the status control on the profile screen, which records who did it and why.',
        );
    }

    /**
     * Your own password is changed on your own account screen, never from here.
     *
     * `UserPolicy::resetPassword()` already says this about the Reset-password button; the field on
     * the edit form is the same action by another name. Setting a password from here revokes the
     * account's other sessions and rotates its remember token, which on your own row is either a
     * half-finished self-logout or a way to skip the current-password confirmation that
     * `/account/password` requires.
     */
    private function assertPasswordIsNotSelfServed(Validator $validator): void
    {
        if (! filled($this->input('password')) || ! $this->targetIsActor()) {
            return;
        }

        $validator->errors()->add(
            'password',
            'Change your own password from your account settings, where your current password is confirmed.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->except(['avatar', 'remove_avatar', 'password_confirmation']);

        $data['must_change_password'] = (bool) ($data['must_change_password'] ?? false);

        // Absent means "leave the role set alone". Writing an empty array here would hand the
        // service a payload that wipes every role.
        if (array_key_exists('roles', $data)) {
            $data['roles'] = array_values(array_map('intval', (array) $data['roles']));
        }

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    public function shouldRemoveAvatar(): bool
    {
        return $this->boolean('remove_avatar') && ! $this->hasFile('avatar');
    }

    private function target(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }

    /**
     * Is the account being edited the account doing the editing?
     */
    public function targetIsActor(): bool
    {
        $target = $this->target();
        $actor = $this->user();

        return $target instanceof User
            && $actor instanceof User
            && $actor->getKey() !== null
            && $actor->getKey() === $target->getKey();
    }
}
