<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Overriding, or returning to, derived progress ([D-P6-3], phase-06 §6.1).
 *
 * The reason is mandatory on the way in, because the override's whole justification is that a human knows
 * something the work below the project does not — and that something has to be written down beside the
 * number for anyone reading it later.
 */
final class SetProjectProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:auto,manual'],
            'progress_percent' => ['required_if:mode,manual', 'nullable', 'numeric', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the derived figure is being overridden, or why it is being restored.',
        ];
    }
}
