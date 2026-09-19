<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A portal login was bound to a client or one of its contacts (phase-05 §6.7 `enablePortal()`, §10.1).
 * The invitation (a password-set link, never a password) is queued by the service itself.
 */
final class ClientPortalEnabled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly User $user,
        public readonly ?ClientContact $contact = null,
        public readonly bool $userCreated = false,
    ) {}
}
