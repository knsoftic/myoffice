<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Enums\Priority;
use App\Enums\ProgressBasis;
use App\Enums\ProjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a project's ordinary details (phase-06 §6.1, §8.2).
 *
 * Note what is **not** here: `project_value`, `discount_amount`, the commission override, the attribution
 * snapshot and every progress column. Each has its own service (INV-P1, INV-P8, INV-P13), and
 * `ProjectService::update()` refuses the request by name if one arrives anyway — a silently dropped field
 * would leave the user with a 200 and no change.
 */
final class UpdateProjectRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'lead_id' => ['sometimes', 'nullable', 'integer', 'exists:leads,id'],
            'project_manager_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'service_id' => ['sometimes', 'nullable', 'integer', 'exists:services,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'project_type' => ['sometimes', 'required', 'string', Rule::in(ProjectType::values())],
            'priority' => ['sometimes', 'required', 'string', Rule::in(Priority::values())],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'deadline' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'budget_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'progress_basis' => ['sometimes', 'required', 'string', Rule::in(ProgressBasis::values())],
        ];
    }
}
