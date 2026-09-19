<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\ClientContactData;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use Illuminate\Support\Facades\DB;

/**
 * A client's named contacts (phase-05 §2.8, §6.7 `ClientContactService`, test 60).
 *
 * **At most one primary contact, enforced by `uq_cc_primary(client_id, primary_guard)`.** Promoting a contact
 * clears the previous primary first and sets the new one second, inside one transaction, so the unique index never
 * sees two primaries. Deleting the primary of a client that still has other contacts is refused until another is
 * promoted — a client with contacts always has a primary to address.
 *
 * A contact reaches the portal only through `ClientService::enablePortal()`; deleting a contact switches its
 * portal access off and ends its sessions first.
 */
final class ClientContactService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    private const MODULE = 'clients';

    public function __construct(
        private readonly ClientPortalAccess $portalAccess,
    ) {}

    public function create(Client $client, ClientContactData $data): ClientContact
    {
        return DB::transaction(function () use ($client, $data): ClientContact {
            $this->lockClient($client);

            if ($data->isPrimary) {
                $this->clearPrimary((int) $client->getKey(), null);
            }

            $contact = new ClientContact;
            $contact->forceFill([
                ...$this->attributes($data),
                'client_id' => (int) $client->getKey(),
                'is_primary' => $data->isPrimary,
            ]);
            $contact->save();

            return $contact;
        });
    }

    public function update(ClientContact $contact, ClientContactData $data): ClientContact
    {
        return DB::transaction(function () use ($contact, $data): ClientContact {
            $locked = $this->lock($contact);

            $locked->forceFill($this->attributes($data));
            $locked->save();

            if ($data->isPrimary && ! (bool) $locked->getAttribute('is_primary')) {
                $this->promote($locked);
            }

            $contact->setRawAttributes($locked->getAttributes(), true);

            return $contact;
        });
    }

    public function setPrimary(ClientContact $contact): ClientContact
    {
        return DB::transaction(function () use ($contact): ClientContact {
            $locked = $this->lock($contact);

            if (! (bool) $locked->getAttribute('is_primary')) {
                $this->promote($locked);
            }

            $contact->setRawAttributes($locked->getAttributes(), true);

            return $contact;
        });
    }

    public function delete(ClientContact $contact, ?string $reason = null): void
    {
        DB::transaction(function () use ($contact, $reason): void {
            $locked = $this->lock($contact);
            $clientId = (int) $locked->getAttribute('client_id');

            if ((bool) $locked->getAttribute('is_primary')) {
                $others = ClientContact::query()
                    ->where('client_id', $clientId)
                    ->whereKeyNot($locked->getKey())
                    ->exists();

                if ($others) {
                    throw CrmRuleException::refuse('contact', 'This is the primary contact. Make another contact primary before deleting it.');
                }
            }

            if ($locked->getAttribute('user_id') !== null || (bool) $locked->getAttribute('portal_access')) {
                $userId = $locked->getAttribute('user_id') === null ? null : (int) $locked->getAttribute('user_id');

                $locked->forceFill(['portal_access' => false]);
                $locked->save();

                if ($userId !== null) {
                    $this->portalAccess->revokeSessions([$userId]);
                }
            }

            $reason = $this->cleanText($reason, 500);

            if ($reason !== null) {
                $locked->withReason($reason);
            }

            $locked->delete();

            $contact->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function promote(ClientContact $contact): void
    {
        $clientId = (int) $contact->getAttribute('client_id');

        $this->clearPrimary($clientId, (int) $contact->getKey());

        $contact->forceFill(['is_primary' => true]);
        $contact->save();
    }

    /**
     * Demote whoever is primary now — before the new primary is written, so `uq_cc_primary` never sees two.
     */
    private function clearPrimary(int $clientId, ?int $exceptId): void
    {
        $current = ClientContact::query()
            ->withTrashed()
            ->where('client_id', $clientId)
            ->where('is_primary', true)
            ->when($exceptId !== null, static fn ($query) => $query->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->get();

        foreach ($current as $previous) {
            $previous->forceFill(['is_primary' => false]);
            $previous->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ClientContactData $data): array
    {
        return [
            'name' => $data->name,
            'designation' => $data->designation,
            'department' => $data->department,
            'email' => $data->email,
            'phone' => $data->phone,
            'whatsapp' => $data->whatsapp,
            'is_billing_contact' => $data->isBillingContact,
            'receives_notifications' => $data->receivesNotifications,
            'notes' => $data->notes,
        ];
    }

    private function lock(ClientContact $contact): ClientContact
    {
        /** @var ClientContact $locked */
        $locked = ClientContact::query()->withTrashed()->whereKey($contact->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function lockClient(Client $client): void
    {
        Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail(['id']);
    }
}
