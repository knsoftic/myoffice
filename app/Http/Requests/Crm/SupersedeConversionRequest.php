<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\LeadConversion;

/**
 * Supersede a conversion so a corrected one can be recorded — `admin.leads.conversions.supersede`
 * (phase-05 §6.4 `supersede(LeadConversion, string $reason)`, Q8, test 49).
 *
 * Needs `leads.edit` + `clients.create` (`LeadConversionPolicy::supersede`); the reason is mandatory. The row is
 * never deleted and the client is never unlinked.
 */
final class SupersedeConversionRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $conversion = $this->boundModel('conversion', LeadConversion::class);

        return $conversion instanceof LeadConversion && $this->actorCan('supersede', $conversion);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this conversion is being superseded.',
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
