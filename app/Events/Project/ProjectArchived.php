<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project was archived — a soft delete that cascades nothing: its tasks, time and value history stay
 * exactly where they are (phase-06 §6.1).
 */
final class ProjectArchived implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ?int $actorId = null,
    ) {}
}
