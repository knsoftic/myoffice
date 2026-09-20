<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project's ordinary details changed (phase-06 §6.1 `update()`). Value, commission and attribution
 * changes are not this event — each has its own, because each is evidence rather than an edit.
 */
final class ProjectUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ?int $actorId = null,
    ) {}
}
