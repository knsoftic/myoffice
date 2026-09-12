<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Flip a module on or off.
 *
 * `enabled` is optional: when it is absent the controller toggles the current state, which is
 * what the switch on the modules screen posts. Sending it explicitly makes the request
 * idempotent, so a double submit cannot flip a module back.
 *
 * `ModulePolicy::toggle()` already refuses core modules; `ModuleService` refuses them again,
 * because a Super Admin bypasses every policy.
 */
final class ToggleModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $module = $this->route('module');

        return $module instanceof Module && Gate::allows('toggle', $module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The requested state, or null to mean "toggle whatever it is now".
     */
    public function desiredState(): ?bool
    {
        return $this->has('enabled') ? $this->boolean('enabled') : null;
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason'));

        return $reason === '' ? null : $reason;
    }
}
