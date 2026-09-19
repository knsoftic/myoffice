<?php

declare(strict_types=1);

namespace App\Policies\Crm\Concerns;

use App\Models\Crm\Client;
use App\Models\User;
use App\Support\ClientContext;
use Throwable;

/**
 * The client a portal user acts for, for a policy decision (phase-05 §9.2).
 *
 * For the signed-in user it is `App\Support\ClientContext` — the one resolution per request every panel query
 * already uses — so a policy can never disagree with the screen it guards. For any other user (a check run through
 * `Gate::forUser()`, a queued notification) it is `Client::resolvePortalFor()`, the same rule applied to that user.
 * The client id is never taken from the request.
 */
trait ResolvesPortalClient
{
    protected function portalClientId(User $user): ?int
    {
        if ($this->isSignedInUser($user)) {
            try {
                $context = app(ClientContext::class);

                return $context->has() ? $context->clientId() : null;
            } catch (Throwable) {
                // Fall through to the model rule: an unavailable context must never widen access.
            }
        }

        $client = Client::resolvePortalFor($user);

        return $client instanceof Client ? (int) $client->getKey() : null;
    }

    private function isSignedInUser(User $user): bool
    {
        try {
            $current = auth()->user();
        } catch (Throwable) {
            return false;
        }

        return $current instanceof User && (int) $current->getKey() === (int) $user->getKey();
    }
}
