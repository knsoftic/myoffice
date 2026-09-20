<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody joined, left, or changed role on a project (phase-06 §6.1 `addMember()` / `updateMemberRole()`
 * / `removeMember()`). Phase 7 reads this for workload.
 */
final class ProjectTeamChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ?int $actorId = null,
    ) {}
}
