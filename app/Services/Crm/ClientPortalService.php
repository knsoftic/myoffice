<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Contracts\Portal\ClientPortalSection;
use App\DataObjects\Crm\ClientProfileData;
use App\DataObjects\Crm\DashboardData;
use App\Events\Crm\ClientUpdated;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Support\ClientPortalRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The client panel's own logic (phase-05 §6.9, §8.10, tests 81-82).
 *
 * **Dashboard** — assembled only from the registered sections the user may see, each asked for its `badgeCount()`
 * once. A section that is not registered (projects before Phase 6, invoices before Phase 13) has no card at all:
 * the dashboard never shows an invented number, and its query count is bounded by the number of visible sections.
 *
 * **Profile** — a strict whitelist (`ClientProfileData`). A client can never change `client_code`, `status`,
 * `portal_enabled`, `account_manager_id`, tax numbers, `payment_terms_days`, `currency` or `notes`: those keys are
 * not in the DTO, so they cannot reach the model whatever a crafted request carries. A signed-in contact edits its
 * own name, designation and phone as well.
 */
final class ClientPortalService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    /** Section keys whose figure the dashboard reports as its own documents / notifications counts. */
    private const OWN_SECTIONS = ['documents', 'notifications'];

    public function __construct(
        private readonly ClientPortalRegistry $sections,
        private readonly ClientService $clients,
    ) {}

    /**
     * The portal's logo field: an uploaded image replaces the logo, `$remove` clears it (§6.9 whitelist `logo_path`).
     */
    public function updateLogo(Client $client, ?UploadedFile $logo, bool $remove = false): Client
    {
        return $this->clients->updateLogo($client, $logo, $remove);
    }

    public function dashboard(Client $client, ?User $user = null): DashboardData
    {
        $user ??= $this->actor();
        $cards = [];
        $own = ['documents' => null, 'notifications' => null];

        if ($user instanceof User) {
            foreach ($this->sections->visibleTo($user, $client) as $section) {
                $count = $this->countFor($section, $client);

                if (in_array($section->key(), self::OWN_SECTIONS, true)) {
                    $own[$section->key()] = $count;
                }

                $cards[] = [
                    'key' => $section->key(),
                    'label' => $section->label(),
                    'icon' => $section->icon(),
                    'count' => $count,
                    'route' => $this->routeFor($section->key()),
                ];
            }
        }

        return new DashboardData(
            client: $client,
            clientName: (string) $client->getAttribute('display_name'),
            cards: $cards,
            documentsCount: $own['documents'],
            unreadNotifications: $own['notifications'],
        );
    }

    public function updateProfile(Client $client, ClientProfileData $data, ?ClientContact $contact = null): Client
    {
        return DB::transaction(function () use ($client, $data, $contact): Client {
            /** @var Client $locked */
            $locked = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            $keys = array_keys($data->client);
            $before = $this->snapshot($locked, $keys);

            foreach ($data->client as $column => $value) {
                // `name` identifies the client; a blank value is ignored rather than erasing it. `logo_path` is written
                // only by `updateLogo()` from an uploaded image — a typed path never reaches the column.
                if (($column === 'name' && ($value === null || $value === '')) || $column === 'logo_path') {
                    continue;
                }

                $locked->setAttribute($column, $value);
            }

            $changes = $this->changes($before, $this->snapshot($locked, $keys));

            if ($changes['attributes'] !== []) {
                $this->withoutModelLogging(static fn (): bool => $locked->save());
                $this->audit($locked, 'Client profile updated from the portal', $changes, 'clients');
                event(new ClientUpdated($locked, $changes, byClient: true));
            }

            if ($contact instanceof ClientContact && $data->contact !== [] && (int) $contact->getAttribute('client_id') === (int) $locked->getKey()) {
                $this->updateContact($contact, $data->contact);
            }

            $client->setRawAttributes($locked->getAttributes(), true);

            return $client;
        });
    }

    /**
     * @param  array<string, string|null>  $values  `contact_name`, `contact_designation`, `contact_phone`
     */
    private function updateContact(ClientContact $contact, array $values): void
    {
        /** @var ClientContact $locked */
        $locked = ClientContact::query()->whereKey($contact->getKey())->lockForUpdate()->firstOrFail();

        $map = ['contact_name' => 'name', 'contact_designation' => 'designation', 'contact_phone' => 'phone'];
        $columns = [];

        foreach ($values as $key => $value) {
            $column = $map[$key] ?? null;

            if ($column === null || ($column === 'name' && ($value === null || $value === ''))) {
                continue;
            }

            $columns[$column] = $value;
        }

        if ($columns === []) {
            return;
        }

        $before = $this->snapshot($locked, array_keys($columns));
        $locked->forceFill($columns);
        $changes = $this->changes($before, $this->snapshot($locked, array_keys($columns)));

        if ($changes['attributes'] !== []) {
            $this->withoutModelLogging(static fn (): bool => $locked->save());
            $this->audit($locked, 'Client contact updated from the portal', $changes, 'clients');
        }

        $contact->setRawAttributes($locked->getAttributes(), true);
    }

    private function countFor(ClientPortalSection $section, Client $client): ?int
    {
        try {
            return $section->badgeCount($client);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function routeFor(string $key): ?string
    {
        $name = match ($key) {
            'progress' => 'client.projects.index',
            default => 'client.'.$key.'.index',
        };

        try {
            return Route::has($name) ? route($name) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
