<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Lead;

/**
 * Edit a lead's profile — `admin.leads.update`, `can:update,lead` (phase-05 §6.1 `update()`, §8.4).
 *
 * Only the §18 profile fields. `lead_no`, `status`, `assigned_to`, `client_id` and `converted_at` each have their
 * own endpoint and method, so they are not listed here and a posted value is dropped. Every field is `sometimes`:
 * the service's `LeadData::$provided` touches only the fields the request actually carried, so a partial update
 * (an inline budget edit) never blanks the rest of the record.
 */
final class UpdateLeadRequest extends StoreLeadRequest
{
    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        return $lead instanceof Lead && $this->actorCan('update', $lead);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->profileRules() as $field => $fieldRules) {
            $rules[$field] = array_merge(['sometimes'], $fieldRules);
        }

        $rules['confirm_duplicate'] = ['nullable', 'boolean'];

        return $rules;
    }

    /**
     * The lead being edited — excluded from its own duplicate report.
     */
    public function lead(): ?Lead
    {
        return $this->boundModel('lead', Lead::class);
    }
}
