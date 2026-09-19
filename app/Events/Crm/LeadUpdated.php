<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead's profile changed (phase-05 §6.1 `update()`, §10.1). `changes` is the audit pair
 * `['old' => [...], 'attributes' => [...]]` of the changed attributes only.
 */
final class LeadUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{old: array<string, mixed>, attributes: array<string, mixed>}  $changes
     */
    public function __construct(
        public readonly Lead $lead,
        public readonly array $changes,
    ) {}
}
