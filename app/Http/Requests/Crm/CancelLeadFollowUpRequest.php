<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\LeadFollowUp;

/**
 * Cancel a follow-up — `admin.leads.follow-ups.cancel`, `can:complete,followUp` (phase-05 §6.3
 * `cancel(LeadFollowUp, string $reason)`). The reason is mandatory (`cancel_reason`).
 */
final class CancelLeadFollowUpRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $followUp = $this->boundModel('followUp', LeadFollowUp::class);

        return $followUp instanceof LeadFollowUp
            && $this->actorCan('complete', $followUp)
            && $this->actorCan('cancel', $followUp);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the follow-up is cancelled.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }

    public function reasonText(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
