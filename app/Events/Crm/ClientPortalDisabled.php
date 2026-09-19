<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Client;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client's portal was switched off and its sessions revoked (phase-05 §6.7 `disablePortal()`, §10.1). The user
 * rows and their history are kept.
 */
final class ClientPortalDisabled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly string $reason,
    ) {}
}
