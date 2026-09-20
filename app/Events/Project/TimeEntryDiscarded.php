<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A time entry was discarded with a reason (phase-06 §6.1). It leaves every cache and rollup in the same
 * transaction, and it is never hard-deleted.
 */
final class TimeEntryDiscarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly string $reason,
        public readonly ?int $actorId = null,
    ) {}
}
