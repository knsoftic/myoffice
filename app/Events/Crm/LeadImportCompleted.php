<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\LeadImport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A CSV import finished — `completed` or `completed_with_errors` (phase-05 §6.6 `run()`, §10.1).
 * Listener: `NotifyImporterOfCompletion` — one notification with the counts and the error-report link.
 */
final class LeadImportCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LeadImport $import,
    ) {}
}
