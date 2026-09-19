<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Client;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client's record changed — from the admin form or the portal profile (phase-05 §6.7, §6.9, §10.1).
 */
final class ClientUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{old: array<string, mixed>, attributes: array<string, mixed>}  $changes
     */
    public function __construct(
        public readonly Client $client,
        public readonly array $changes,
        public readonly bool $byClient = false,
    ) {}
}
