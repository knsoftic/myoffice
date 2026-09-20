<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project was registered (phase-06 §6.1 `create()`, §10.1).
 *
 * Listener: the Phase 9 backfill, which turns a captured referral code into a `collaborator_referrals`
 * row once that table exists.
 */
final class ProjectCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ?int $actorId = null,
    ) {}
}
