<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Enums\CommissionCalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a project's contract value or commission override (phase-06 §6.1, requirement §107, INV-P1).
 *
 * The reason and the effective date are **required**, always: a value that moved without either is the
 * thing this whole table exists to make impossible. `effective_on` is the business date the new value
 * applies from, which is not the same as when somebody typed it — the spine reads that date months later
 * to decide which entitlements were promised against which value.
 *
 * The no-op case is refused by the service and by `chk_pvr_change`, not here: whether anything changed
 * depends on the row as it stands, which a request cannot see.
 */
final class ReviseProjectValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('revise', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999999'],
            'discount_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999999'],
            'commission_type' => [
                'sometimes', 'nullable', 'string',
                // `manual` is a ledger adjustment, never a project override (chk_projects_commission).
                Rule::in([CommissionCalculationType::Percentage->value, CommissionCalculationType::Fixed->value]),
            ],
            'commission_rate' => [
                'nullable', 'numeric', 'min:0', 'max:100',
                Rule::requiredIf(fn (): bool => $this->input('commission_type') === CommissionCalculationType::Percentage->value),
            ],
            'commission_fixed_amount' => [
                'nullable', 'numeric', 'min:0', 'max:9999999999999',
                Rule::requiredIf(fn (): bool => $this->input('commission_type') === CommissionCalculationType::Fixed->value),
            ],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'effective_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the project value is changing.',
            'commission_rate.required' => 'A percentage override needs a rate.',
            'commission_fixed_amount.required' => 'A fixed override needs an amount.',
        ];
    }
}
