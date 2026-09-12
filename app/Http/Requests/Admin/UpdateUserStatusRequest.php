<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Activate / deactivate / suspend an account.
 *
 * The reason is mandatory whenever the new state takes access away — `users.status_reason` is
 * what the login screen and the audit trail quote back, so "suspended, no idea why" is not an
 * acceptable record (phase-01 §1.1, §7).
 */
final class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && Gate::allows('changeStatus', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(UserStatus::class)],

            'reason' => [
                Rule::requiredIf(fn (): bool => $this->newStatus() !== null && ! $this->newStatus()->canLogin()),
                'nullable',
                'string',
                'min:3',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why access is being restricted — it is shown in the audit trail.',
        ];
    }

    /**
     * The requested state, or null when the payload is not a known status.
     */
    public function newStatus(): ?UserStatus
    {
        $status = $this->input('status');

        return is_string($status) ? UserStatus::tryFrom($status) : null;
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason'));

        return $reason === '' ? null : $reason;
    }
}
