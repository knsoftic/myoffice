<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user linked a lead to the original it duplicates (phase-05 §6.1 `linkDuplicate()`, §10.1).
 */
final class LeadDuplicateLinked implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $duplicate,
        public readonly Lead $original,
        public readonly ?int $actorId = null,
    ) {}
}
