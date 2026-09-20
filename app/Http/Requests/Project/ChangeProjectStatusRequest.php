<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a project between the eight statuses of §20 (phase-06 §2.13.1).
 *
 * The reason is validated as `required_if` for the three moves §2.13.1 marks mandatory, and
 * `ProjectService::changeStatus()` checks it again — the request can be bypassed by a console command or a
 * later phase, the service cannot.
 */
final class ChangeProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ProjectStatus::values())],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('status'),
                    [ProjectStatus::OnHold->value, ProjectStatus::Cancelled->value],
                    true
                )),
                'nullable',
                'string',
                'min:5',
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
            'reason.required' => 'Say why the project is being paused or cancelled.',
            'reason.min' => 'Give a reason somebody reading this later can act on.',
        ];
    }
}
