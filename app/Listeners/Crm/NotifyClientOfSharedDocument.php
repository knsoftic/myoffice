<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\ClientDocumentSharedWithClient;
use App\Listeners\Cms\Concerns\NotifiesStaff;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\ClientDocument;
use App\Models\User;
use App\Notifications\Crm\ClientDocumentShared;
use App\Support\Modules;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * `ClientDocumentShared` to a client's portal logins when a document becomes visible (phase-05 §6.8, test 68).
 *
 * Recipients: the client's primary login and every contact with portal access that still receives notifications,
 * active accounts only, and only while the client may use the portal. Re-reads the document first: one hidden
 * again, or deleted, before the worker ran is not announced.
 */
final class NotifyClientOfSharedDocument implements ShouldQueue
{
    use NotifiesStaff;

    public bool $deleteWhenMissingModels = true;

    public function handle(ClientDocumentSharedWithClient $event): void
    {
        if (! Modules::enabled('client_documents')) {
            return;
        }

        $document = ClientDocument::query()->find($event->document->getKey());

        if (! $document instanceof ClientDocument || ! (bool) $document->getAttribute('visible_to_client')) {
            return;
        }

        $client = Client::query()->find($document->getAttribute('client_id'));

        if (! $client instanceof Client || ! $client->canUsePortal()) {
            return;
        }

        $ids = ClientContact::query()
            ->where('client_id', $client->getKey())
            ->where('portal_access', true)
            ->where('receives_notifications', true)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($client->getAttribute('user_id') !== null) {
            $ids[] = (int) $client->getAttribute('user_id');
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        $this->deliver(User::query()->active()->whereKey($ids)->get(), [], new ClientDocumentShared($document));
    }
}
