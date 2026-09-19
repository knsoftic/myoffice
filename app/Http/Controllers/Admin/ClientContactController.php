<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreClientContactRequest;
use App\Http\Requests\Crm\UpdateClientContactRequest;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Services\Crm\ClientContactService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client's named contacts — `admin.clients.contacts.*` (phase-05 §2.8, §6.7 `ClientContactService`, §8.8 Contacts
 * tab, test 60), `module:clients`.
 *
 * `{contact}` must belong to `{client}`; any other pairing is a 404. Promoting a primary contact demotes the previous
 * one inside one transaction (so `uq_cc_primary` is never violated), and deleting the only primary contact of a
 * client that has others is refused until another is promoted — both rules live in the service.
 */
final class ClientContactController extends Controller
{
    use RespondsForCrm;

    public function __construct(
        private readonly ClientContactService $contacts,
    ) {}

    public function store(StoreClientContactRequest $request, Client $client): Response
    {
        $this->authorize('update', $client);

        return $this->attempt($request, function () use ($request, $client): Response {
            $contact = $this->contacts->create($client, $request->toData());

            return $this->done(
                $request,
                sprintf('%s was added as a contact.', $contact->name),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'contacts']),
                ['id' => (int) $contact->getKey()],
            );
        });
    }

    public function update(UpdateClientContactRequest $request, Client $client, ClientContact $contact): Response
    {
        $this->assertBelongs($client, $contact);
        $this->authorize('update', $contact);

        return $this->attempt($request, function () use ($request, $client, $contact): Response {
            $contact = $this->contacts->update($contact, $request->toData());

            return $this->done(
                $request,
                sprintf('%s was updated.', $contact->name),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'contacts']),
                ['id' => (int) $contact->getKey()],
            );
        });
    }

    public function primary(Request $request, Client $client, ClientContact $contact): Response
    {
        $this->assertBelongs($client, $contact);
        $this->authorize('update', $contact);

        return $this->attempt($request, function () use ($request, $client, $contact): Response {
            $this->contacts->setPrimary($contact);

            return $this->done(
                $request,
                sprintf('%s is now the primary contact.', $contact->name),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'contacts']),
                ['id' => (int) $contact->getKey()],
            );
        });
    }

    public function destroy(Request $request, Client $client, ClientContact $contact): Response
    {
        $this->assertBelongs($client, $contact);
        $this->authorize('delete', $contact);

        return $this->attempt($request, function () use ($request, $client, $contact): Response {
            $this->contacts->delete($contact);

            return $this->done(
                $request,
                sprintf('%s was removed from the contacts.', $contact->name),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'contacts']),
                ['id' => (int) $contact->getKey()],
            );
        });
    }

    private function assertBelongs(Client $client, ClientContact $contact): void
    {
        $this->abortUnlessVisible((int) $contact->client_id === (int) $client->getKey());
    }
}
