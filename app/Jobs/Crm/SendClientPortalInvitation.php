<?php

declare(strict_types=1);

namespace App\Jobs\Crm;

use App\Models\Crm\Client;
use App\Models\User;
use App\Notifications\Crm\ClientPortalInvitation;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a client portal invitation (phase-05 §6.7 `enablePortal()`, §10.3, §10.4, test 62).
 *
 * The invitation is a **password-set link** built from a fresh password-broker token — the same reset flow every
 * user has — and never carries a password. Retryable (3 tries); the payload is ids only, so nothing sensitive sits
 * in the queue. A user who is no longer active, or a client whose portal was switched off before the worker ran,
 * receives nothing.
 */
final class SendClientPortalInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
        public readonly int $clientId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PasswordBroker $passwords): void
    {
        $user = User::query()->active()->find($this->userId);
        $client = Client::query()->find($this->clientId);

        if (! $user instanceof User || ! $client instanceof Client || ! (bool) $client->getAttribute('portal_enabled')) {
            return;
        }

        $token = $passwords->createToken($user);

        $user->notify(new ClientPortalInvitation($client, $token));
    }
}
