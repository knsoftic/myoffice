<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\OutcomeData;
use App\Enums\LeadContactOutcome;
use App\Enums\LeadFollowUpType;
use App\Models\Crm\LeadFollowUp;
use Illuminate\Validation\Rule;

/**
 * Complete a follow-up — `admin.leads.follow-ups.complete`, `can:complete,followUp` (phase-05 §6.3 `complete()`,
 * test 31).
 *
 * `outcome` is mandatory (422 without it). An optional `next` block schedules the successor in the same
 * transaction, with the same rules as a fresh follow-up.
 */
final class CompleteLeadFollowUpRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $followUp = $this->boundModel('followUp', LeadFollowUp::class);

        return $followUp instanceof LeadFollowUp && $this->actorCan('complete', $followUp);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'outcome' => ['required', 'string', Rule::enum(LeadContactOutcome::class)],
            'outcome_note' => ['nullable', 'string', 'max:255'],
            'schedule_next' => ['nullable', 'boolean'],
        ], $this->followUpRules('next', false, LeadFollowUpType::values()));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'outcome.required' => 'Choose how the follow-up went.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['outcome', 'outcome_note']);

        $next = $this->input('next');

        // The dialog's "schedule the next one" switch: off means no successor, whatever the hidden inputs still hold.
        if ($this->has('schedule_next') && ! $this->boolean('schedule_next')) {
            $this->merge(['next' => null]);

            return;
        }

        if (is_array($next) && array_filter($next, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
            $this->merge(['next' => null]);
        }
    }

    public function toData(): OutcomeData
    {
        return OutcomeData::fromArray($this->validated());
    }
}
