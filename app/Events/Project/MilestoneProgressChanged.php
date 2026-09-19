<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\ProjectMilestone;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A milestone's derived progress moved (phase-06 §6.1 `recalculateMilestone()`, §10.1).
 *
 * Fired once per row that actually changed, after commit.
 */
final class MilestoneProgressChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ProjectMilestone $milestone,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
