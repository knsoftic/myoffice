<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Enums\Priority;
use App\Enums\ProgressBasis;
use App\Enums\ProjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registering a project (phase-06 §6.1, §8.2).
 *
 * `project_value` is accepted **here only**: §6.1 writes the opening value with the project and records it
 * as revision 1. Every later change goes through `ReviseProjectValueRequest`, because a contract value
 * that moved without a reason is exactly what requirement §107 exists to prevent.
 *
 * `code`, `status`, the commission override, the attribution snapshot and every progress column are absent
 * on purpose — each has its own service, and the model hooks refuse them whatever arrives.
 */
final class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('projects.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'project_manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_type' => ['required', 'string', Rule::in(ProjectType::values())],
            'priority' => ['required', 'string', Rule::in(Priority::values())],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'project_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'progress_basis' => ['nullable', 'string', Rule::in(ProgressBasis::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deadline.after_or_equal' => 'The deadline cannot fall before the start date.',
        ];
    }
}
