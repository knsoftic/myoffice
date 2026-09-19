<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Http\Requests\Crm\Concerns\ValidatesLeadIds;

/**
 * Soft-delete many leads — `admin.leads.bulk.destroy`, `can:leads.delete` (phase-05 §7, §8.1 bulk bar).
 *
 * The same capped explicit id list as the other bulk actions, plus the mandatory reason `LeadService::delete()`
 * takes. A lead with a live conversion is refused per row by the service (§6.1), and an id the actor cannot see is
 * reported as forbidden, never deleted.
 */
final class BulkLeadDestroyRequest extends CrmFormRequest
{
    use ValidatesLeadIds;

    public function authorize(): bool
    {
        return $this->actorCan('leads.delete');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->idRules(), [
            'reason' => $this->requiredReasonRules(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->idMessages(), [
            'reason.required' => 'Say why these leads are being deleted.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }
}
