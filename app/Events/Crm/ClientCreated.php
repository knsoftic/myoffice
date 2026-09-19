<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Client;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client record was created — by hand or by a lead conversion (phase-05 §6.7 `create()`, §10.1).
 * Listener: `RecordCapturedReferral`.
 */
final class ClientCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly ?int $actorId = null,
    ) {}
}
