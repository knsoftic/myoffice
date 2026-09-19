<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\Client;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;
use App\Policies\Crm\Concerns\ResolvesPortalClient;
use Illuminate\Auth\Access\Response;

/**
 * Who may work the client master, and what a client may do with its own record (phase-05 §4.2, §9.1, §9.2, §9.3).
 *
 * **Staff.** Every method checks its `clients.*` ability first. Clients carry no per-row staff scope (§9.1 — a
 * `clients.view` holder reads every client); financial figures need `clients.view_financial` on top
 * ({@see viewFinancial()}, §11 test 58). A trashed client is read-only until restored.
 *
 * **Portal management** creates or links a login and assigns it the `Client` role, so it needs
 * `clients.change_status` **and** `users.create` (§6.7 `enablePortal`).
 *
 * **The client itself.** {@see viewOwn()} / {@see updateOwnProfile()} need `client_portal.profile` and are true
 * only for the client the signed-in user acts for (`ClientContext`); any other client answers **404** (§9.2). The
 * writable field set is the `UpdateClientProfileRequest` whitelist, never this policy (§11 test 81).
 */
final class ClientPolicy
{
    use ChecksCrmPermissions;
    use ResolvesPortalClient;

    public const MODULE = 'clients';

    /** The portal permission namespace (D20: a permission prefix, not a module). */
    public const PORTAL_PROFILE_PERMISSION = 'client_portal.profile';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->liveRow($user, $client, Ability::Edit);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->liveRow($user, $client, Ability::Delete);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($client);
    }

    /**
     * Super Admin only — `Gate::before` grants it; nobody else may. A client with a conversion on record cannot be
     * removed at all (`lead_conversions.client_id` is `restrictOnDelete`).
     */
    public function forceDelete(User $user, Client $client): bool
    {
        return false;
    }

    public function changeStatus(User $user, Client $client): bool
    {
        return $this->liveRow($user, $client, Ability::ChangeStatus);
    }

    /**
     * Assign the account manager (§6.7 `assignAccountManager`).
     */
    public function assign(User $user, Client $client): bool
    {
        return $this->liveRow($user, $client, Ability::Assign);
    }

    /**
     * The invoiced / paid / outstanding block and the outstanding column (§8.7, §8.8). Without it the figures are
     * absent from the query and the response, not hidden by CSS.
     */
    public function viewFinancial(User $user, ?Client $client = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    /**
     * Enable or disable the portal, send or resend the invitation.
     */
    public function managePortal(User $user, Client $client): bool
    {
        return ! $this->isTrashed($client)
            && $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $this->holds($user, 'users', Ability::Create);
    }

    public function print(User $user, ?Client $client = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * The client panel profile screen: the user's own client only, else 404.
     */
    public function viewOwn(User $user, Client $client): Response|bool
    {
        if (! $user->can(self::PORTAL_PROFILE_PERMISSION)) {
            return false;
        }

        return $this->isOwnClient($user, $client) ? true : Response::denyAsNotFound();
    }

    /**
     * The client panel profile update: the user's own client only, else 404.
     */
    public function updateOwnProfile(User $user, Client $client): Response|bool
    {
        return $this->viewOwn($user, $client);
    }

    private function isOwnClient(User $user, Client $client): bool
    {
        $own = $this->portalClientId($user);

        return $own !== null && $own === (int) $client->getKey();
    }

    private function liveRow(User $user, Client $client, Ability $ability): bool
    {
        return $this->holds($user, self::MODULE, $ability) && ! $this->isTrashed($client);
    }
}
