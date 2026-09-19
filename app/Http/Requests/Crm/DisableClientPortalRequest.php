<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Client;

/**
 * Switch a client's portal off — `admin.clients.portal.disable`, `can:managePortal,client` (phase-05 §6.7
 * `disablePortal(Client, string $reason)`). The reason is mandatory; the user row and its history are kept.
 */
final class DisableClientPortalRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client && $this->actorCan('managePortal', $client);
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
            'reason.required' => 'Say why portal access is being switched off.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }
}
