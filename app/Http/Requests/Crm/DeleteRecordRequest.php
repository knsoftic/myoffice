<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\Lead;

/**
 * The mandatory reason of a soft delete (phase-05 §6.1 `LeadService::delete(Lead, string $reason)`, §6.7
 * `ClientService::delete(Client, string $reason)`):
 *
 *   `admin.leads.destroy`    DELETE  `can:delete,lead`
 *   `admin.clients.destroy`  DELETE  `can:delete,client`
 *
 * The bound model decides which policy is asked; a route binding neither refuses.
 */
final class DeleteRecordRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        if ($lead instanceof Lead) {
            return $this->actorCan('delete', $lead);
        }

        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client && $this->actorCan('delete', $client);
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
            'reason.required' => 'Say why this record is being deleted.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }
}
