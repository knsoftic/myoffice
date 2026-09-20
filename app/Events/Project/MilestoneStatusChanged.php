<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\ProjectMilestone;
use App\Enums\MilestoneStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A milestone moved status (phase-06 §2.13.2), by hand or because its tasks did.
 */
final class MilestoneStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ProjectMilestone $milestone,
        public readonly MilestoneStatus $from,
        public readonly MilestoneStatus $to,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
