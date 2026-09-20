<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project's attribution moved to a different collaborator, with a reason (phase-06 INV-P13). No ledger
 * row is ever re-pointed: the spine supersedes the old referral and opens a new one.
 */
final class ProjectReferralChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
