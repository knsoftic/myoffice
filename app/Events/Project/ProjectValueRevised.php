<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectValueRevision;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project's contract value or commission override changed, with its reason (phase-06 §6.1, INV-P1).
 *
 * The spine's §6.6 case 8 listens for this to decide what happens to an entitlement already promised
 * against the old value. It carries the revision row, not just the project, because the row is the
 * evidence — it holds both ends, the signed delta and the business date the new value applies from.
 */
final class ProjectValueRevised implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectValueRevision $revision,
        public readonly ?int $actorId = null,
    ) {}
}
