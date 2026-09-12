<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Force a temporary password onto someone else's account.
 *
 * No password is accepted from the client: the service generates one, so an administrator can
 * never choose (or re-use) a password on a colleague's behalf. `UserPolicy::resetPassword()`
 * also refuses the actor's own row — your own password is changed through the account screen.
 */
final class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && Gate::allows('resetPassword', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason'));

        return $reason === '' ? null : $reason;
    }
}
