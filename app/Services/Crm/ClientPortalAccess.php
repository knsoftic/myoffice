<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ending a client's portal sessions (phase-05 §6.7 `changeStatus()` / `disablePortal()`, test 63).
 *
 * Revocation is two layers, both immediate:
 *   · this class deletes the users' stored sessions and rotates their remember-me tokens, so a revoked login is
 *     signed out rather than left holding a live session;
 *   · `EnsureClientContext` re-evaluates `portal_enabled`, the client's status and the contact's access on every
 *     `/client` request, so even a session that survives (another driver, a request already in flight) is refused
 *     on its next request.
 * Neither touches `portal_enabled`, the user rows or their history — restoring the status restores access.
 */
final class ClientPortalAccess
{
    /**
     * Every user id that can reach the panel as this client: the primary login and contacts with portal access.
     *
     * @return list<int>
     */
    public function portalUserIds(Client $client): array
    {
        $ids = [];

        if ($client->getAttribute('user_id') !== null) {
            $ids[] = (int) $client->getAttribute('user_id');
        }

        $contactIds = ClientContact::query()
            ->withTrashed()
            ->where('client_id', $client->getKey())
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$contactIds]));
    }

    /**
     * @param  list<int>  $userIds
     */
    public function revokeSessions(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        if ($userIds === []) {
            return;
        }

        try {
            if (Schema::hasTable('sessions')) {
                $current = null;

                try {
                    $current = request()->hasSession() ? request()->session()->getId() : null;
                } catch (Throwable) {
                    $current = null;
                }

                Session::query()
                    ->whereIn('user_id', $userIds)
                    ->when($current !== null, static fn ($query) => $query->where('id', '!=', $current))
                    ->delete();
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            foreach ($userIds as $id) {
                User::query()->whereKey($id)->toBase()->update(['remember_token' => Str::random(60)]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function revokeFor(Client $client): void
    {
        $this->revokeSessions($this->portalUserIds($client));
    }
}
