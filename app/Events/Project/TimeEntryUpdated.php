<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A manual time entry was corrected (phase-06 §6.1). A timer entry never reaches here — it is evidence of
 * when work happened, so it is discarded with a reason and re-entered.
 */
final class TimeEntryUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly ?int $actorId = null,
    ) {}
}
