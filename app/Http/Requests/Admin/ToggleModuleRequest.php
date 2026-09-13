<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Flip a module on or off (`admin.modules.toggle`).
 *
 * `enabled` is optional: when it is absent the service toggles the current state, which is what
 * the switch on the modules screen posts. Sending it explicitly makes the request idempotent, so
 * a double submit cannot flip a module back.
 *
 * **D63 — a disable requires a reason on the server** (phase-02 §5), 5 to 255 characters. The
 * rule is decided on the *target* state, not on the literal `enabled` field: a post with no
 * `enabled` key flips the current state, so switching an enabled module off that way needs a
 * reason just the same. Enabling needs none. The impact dialog marks the field required in the
 * browser too, but that was never security — a crafted POST used to switch a module off with a
 * generated sentence instead of a human explanation. `ModuleService` refuses an empty reason again.
 *
 * `cascade` is the explicit "yes, take the dependent modules down with it" confirmation. It must
 * be sent by the caller every single time: phase-02 §3 refuses a disable that would break an
 * enabled dependent unless the cascade was asked for, and a default of true would turn one
 * unchecked click into a chain of disabled modules.
 *
 * `ModulePolicy::toggle()` already refuses core modules; `ModuleService` refuses them again,
 * because a Super Admin bypasses every policy.
 */
final class ToggleModuleRequest extends FormRequest
{
    /** D63 bounds for a disable reason. */
    public const REASON_MIN = 5;

    public const REASON_MAX = 255;

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
            'reason' => $this->isDisable()
                ? ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX]
                : ['nullable', 'string', 'max:'.self::REASON_MAX],
            'cascade' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason for switching this module off. It is recorded in the audit trail.',
            'reason.min' => 'The reason must be at least :min characters.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cascade' => 'cascade confirmation',
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
        $reason = $this->input('reason');
        $reason = is_string($reason) ? trim($reason) : '';

        return $reason === '' ? null : $reason;
    }

    /**
     * Did the administrator explicitly accept taking the dependent modules down too?
     */
    public function cascade(): bool
    {
        return $this->boolean('cascade');
    }

    /**
     * Is the module being moved to "off"? An absent `enabled` flips the current state.
     */
    private function isDisable(): bool
    {
        $desired = $this->desiredState();

        if ($desired !== null) {
            return $desired === false;
        }

        $module = $this->route('module');

        return $module instanceof Module && (bool) $module->is_enabled;
    }
}
