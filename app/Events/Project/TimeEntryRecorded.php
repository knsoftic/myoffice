<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\TimeEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Time landed against a project — a stopped timer or a manual entry (phase-06 §6.1).
 *
 * The single event later phases listen to for "hours exist now"; how they were captured is
 * `$entry->source`.
 */
final class TimeEntryRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly ?int $actorId = null,
    ) {}
}
