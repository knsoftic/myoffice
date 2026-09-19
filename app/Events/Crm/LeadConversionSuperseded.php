<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadConversion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A conversion was superseded so a corrected one can be recorded (phase-05 §6.4 `supersede()`, §10.1). The row
 * itself is never deleted and the client is never unlinked.
 */
final class LeadConversionSuperseded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadConversion $conversion,
        public readonly string $reason,
        public readonly ?int $actorId = null,
    ) {}
}
