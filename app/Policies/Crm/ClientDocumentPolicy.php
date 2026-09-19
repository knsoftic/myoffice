<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\Client;
use App\Models\Crm\ClientDocument;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;
use App\Policies\Crm\Concerns\ResolvesPortalClient;
use Illuminate\Auth\Access\Response;

/**
 * Who may list, upload, share and download a client's documents (phase-05 §4.1, §6.8, §9.1, §9.2, §9.3).
 *
 * **Staff** need `client_documents.*` — the module of §4.1, separate from editing the client record. `visible_to_client`
 * has **no effect on staff** (§9.1). Downloading is its own ability: without `client_documents.download` the list
 * still renders while the download route is refused (§11 test 70). `change_status` is the ability that means
 * "share with / unshare from the client portal" ({@see changeVisibility()}).
 *
 * **The client** ({@see downloadAsClient()}) needs `client_portal.download`, and the document must belong to the
 * client the signed-in user acts for, be `visible_to_client` and not be trashed. Anything else answers **404** —
 * another client's id and an unshared document of the user's own client alike, so neither can be probed (§11
 * test 67).
 *
 * A force delete removes the file: Super Admin only (`Gate::before`), never granted here.
 */
final class ClientDocumentPolicy
{
    use ChecksCrmPermissions;
    use ResolvesPortalClient;

    public const MODULE = 'client_documents';

    /** The portal permission namespace (D20: a permission prefix, not a module). */
    public const PORTAL_DOWNLOAD_PERMISSION = 'client_portal.download';

    public function viewAny(User $user, ?Client $client = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, ClientDocument $document): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function upload(User $user, ?Client $client = null): bool
    {
        return $this->holds($user, self::MODULE, Ability::Upload) && ! $this->isTrashed($client);
    }

    public function update(User $user, ClientDocument $document): bool
    {
        return $this->liveDocument($user, $document, Ability::Edit);
    }

    /**
     * Share with, or withdraw from, the client portal.
     */
    public function changeVisibility(User $user, ClientDocument $document): bool
    {
        return $this->liveDocument($user, $document, Ability::ChangeStatus);
    }

    /**
     * Staff download: `client_documents.download` on a document the user may see.
     */
    public function download(User $user, ClientDocument $document): bool
    {
        return $this->view($user, $document)
            && $this->holds($user, self::MODULE, Ability::Download)
            && ! $this->isTrashed($document);
    }

    /**
     * Portal download: own client, shared, live — else 404.
     */
    public function downloadAsClient(User $user, ClientDocument $document): Response|bool
    {
        if (! $user->can(self::PORTAL_DOWNLOAD_PERMISSION)) {
            return false;
        }

        $own = $this->portalClientId($user);

        if ($own === null
            || ! $document->belongsToClient($own)
            || ! $document->isVisibleToClient()
            || $this->isTrashed($document)) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    public function delete(User $user, ClientDocument $document): bool
    {
        return $this->liveDocument($user, $document, Ability::Delete);
    }

    /**
     * Super Admin only — `Gate::before` grants it; nobody else may.
     */
    public function forceDelete(User $user, ClientDocument $document): bool
    {
        return false;
    }

    private function liveDocument(User $user, ClientDocument $document, Ability $ability): bool
    {
        return $this->holds($user, self::MODULE, $ability) && ! $this->isTrashed($document);
    }
}
