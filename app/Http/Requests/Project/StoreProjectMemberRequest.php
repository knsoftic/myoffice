<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Enums\ProjectMemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Putting somebody on a project team (phase-06 §2.3, §6.1).
 *
 * Exactly one of `user_id` / `collaborator_id` — INV-P10 as a validation rule, so the user sees a sentence
 * rather than `chk_pm_one_party`. The database and the model both check it again: this is the friendly
 * layer, not the enforcing one.
 */
final class StoreProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assign', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'collaborator_id' => ['nullable', 'integer'],
            'role' => ['required', 'string', Rule::in(ProjectMemberRole::values())],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasUser = $this->filled('user_id');
            $hasCollaborator = $this->filled('collaborator_id');

            if ($hasUser === $hasCollaborator) {
                $validator->errors()->add('user_id', 'Choose either a staff member or a collaborator, not both.');
            }
        });
    }
}
