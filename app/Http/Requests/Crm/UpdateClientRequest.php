<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Client;

/**
 * Edit a client — `admin.clients.update`, `can:update,client` (phase-05 §6.7 `update()`, §8.8).
 *
 * The §19 fields only, each `sometimes` so the service's `ClientData::$provided` touches just what was sent.
 * `client_code`, `user_id`, `status` and `portal_enabled` are never accepted (the service would ignore them too),
 * and the account manager has its own route (`admin.clients.account-manager`, `can:assign,client`).
 */
final class UpdateClientRequest extends StoreClientRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client && $this->actorCan('update', $client);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->clientFieldRules('', true);
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings($this->clientFieldNames());
    }
}
