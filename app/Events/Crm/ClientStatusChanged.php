<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Enums\ClientStatus;
use App\Models\Crm\Client;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client's status changed (phase-05 §6.7 `changeStatus()`, §10.1). A status whose `canUsePortal()` is false
 * has already revoked the portal sessions inside the transaction.
 */
final class ClientStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly ClientStatus $from,
        public readonly ClientStatus $to,
        public readonly ?string $reason = null,
    ) {}
}
