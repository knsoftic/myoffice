<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;

/**
 * Who may manage a client's contacts (phase-05 §7, §9.3).
 *
 * Contacts are part of the client record: reading them needs `clients.view`, adding, editing, promoting to primary
 * and removing them need `clients.edit` (the contact routes gate on `update,client` / `update,contact` /
 * `delete,contact`) on a live client and a live contact. Whether the only primary of a multi-contact client may
 * be removed is `ClientContactService`'s rule (§6.7, test 60).
 *
 * {@see grantPortalAccess()} binds a login to the contact, so it needs what enabling the portal needs:
 * `clients.change_status` **and** `users.create` (§6.7 `enablePortal`).
 */
final class ClientContactPolicy
{
    use ChecksCrmPermissions;

    public function view(User $user, ClientContact $contact): bool
    {
        return $this->holds($user, ClientPolicy::MODULE, Ability::View);
    }

    public function create(User $user, ?Client $client = null): bool
    {
        return $this->holds($user, ClientPolicy::MODULE, Ability::Edit) && ! $this->isTrashed($client);
    }

    public function update(User $user, ClientContact $contact): bool
    {
        return $this->editable($user, $contact);
    }

    public function delete(User $user, ClientContact $contact): bool
    {
        return $this->editable($user, $contact);
    }

    public function grantPortalAccess(User $user, ClientContact $contact): bool
    {
        return $this->editable($user, $contact)
            && $this->holds($user, ClientPolicy::MODULE, Ability::ChangeStatus)
            && $this->holds($user, 'users', Ability::Create);
    }

    private function editable(User $user, ClientContact $contact): bool
    {
        if (! $this->holds($user, ClientPolicy::MODULE, Ability::Edit) || $this->isTrashed($contact)) {
            return false;
        }

        $client = $contact->client;

        return $client instanceof Client && ! $this->isTrashed($client);
    }
}
