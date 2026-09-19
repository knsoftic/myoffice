<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Enums\LeadStatus;
use App\Http\Requests\Crm\Concerns\ValidatesLeadIds;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Change the status of many leads — `admin.leads.bulk.status`, `can:leads.change_status` (phase-05 §6.1
 * `bulkChangeStatus()`, §8.1).
 *
 * Each row is validated against §2.11 **individually by the service**: an illegal transition is a per-row `skipped`
 * with its reason, never a 500 and never a silent partial write (test 35). The request only checks the shape: the
 * capped id list, a real `LeadStatus`, and a `lost_reason` (or `reason`) whenever the target is `lost` — it is
 * written as the lost reason of every moved row.
 */
final class BulkLeadStatusRequest extends CrmFormRequest
{
    use ValidatesLeadIds;

    public function authorize(): bool
    {
        return $this->actorCan('leads.change_status');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->idRules(), [
            'to_status' => ['required', 'string', Rule::enum(LeadStatus::class)],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('to_status') === LeadStatus::Lost->value && $this->statusReason() === null) {
                    $validator->errors()->add('lost_reason', 'Say why these leads were lost.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['to_status', 'lost_reason', 'reason']);
    }

    /**
     * The reason handed to the service: the lost reason when one was given, else the free reason.
     */
    public function statusReason(): ?string
    {
        foreach (['lost_reason', 'reason'] as $key) {
            $value = $this->input($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    public function targetStatus(): LeadStatus
    {
        return LeadStatus::from((string) $this->validated('to_status'));
    }
}
