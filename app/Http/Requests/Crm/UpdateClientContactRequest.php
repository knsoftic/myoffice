<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;

/**
 * Edit a client contact — `admin.clients.contacts.update`, `can:update,contact` (phase-05 §6.7
 * `ClientContactService::update()`).
 *
 * The same fields as a new contact. The contact must belong to the client in the URL; the controller answers 404
 * otherwise, and this request refuses before any rule runs.
 */
final class UpdateClientContactRequest extends StoreClientContactRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);
        $contact = $this->boundModel('contact', ClientContact::class);

        return $client instanceof Client
            && $contact instanceof ClientContact
            && (int) $contact->client_id === (int) $client->getKey()
            && $this->actorCan('update', $contact);
    }
}
