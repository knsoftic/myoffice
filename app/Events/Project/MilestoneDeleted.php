<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\ProjectMilestone;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A milestone was soft-deleted (phase-06 §6.1). Its tasks are detached, never deleted; a milestone with a
 * payment against it is refused before it gets here.
 */
final class MilestoneDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ProjectMilestone $milestone,
        public readonly ?int $actorId = null,
    ) {}
}
