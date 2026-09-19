<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\ClientContactData;
use App\Models\Crm\Client;

/**
 * Add a named contact to a client — `admin.clients.contacts.store`, `can:update,client` (phase-05 §2.8, §6.7
 * `ClientContactService`, §8.8 Contacts tab).
 *
 * The `ClientContactData` keys. `is_primary` asks the service to promote this contact inside one transaction, so
 * `uq_cc_primary` is never violated (test 60). A portal login for a contact is created through
 * `admin.clients.portal.enable` with `client_contact_id`, never by posting `user_id` here.
 */
class StoreClientContactRequest extends CrmFormRequest
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:96'],
            'department' => ['nullable', 'string', 'max:96'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'phone' => $this->phoneRules(),
            'whatsapp' => $this->phoneRules(),
            'is_primary' => ['nullable', 'boolean'],
            'is_billing_contact' => ['nullable', 'boolean'],
            'receives_notifications' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name', 'designation', 'department', 'email', 'phone', 'whatsapp', 'notes']);
    }

    public function toData(): ClientContactData
    {
        return ClientContactData::fromArray($this->validated());
    }
}
