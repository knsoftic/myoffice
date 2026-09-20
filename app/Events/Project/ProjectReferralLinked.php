<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project was attributed to a collaborator (phase-06 §6.1, INV-P13).
 *
 * Until Phase 9/10 ships `ReferralService`, this event is how the attribution reaches the spine: the
 * listener backfills a `collaborator_referrals` row, which is the authority — `projects.collaborator_id`
 * is only a display snapshot (R5, D37).
 */
final class ProjectReferralLinked implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
