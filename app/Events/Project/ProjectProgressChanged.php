<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project's progress moved (phase-06 §6.1, §10.1).
 *
 * `$manual` separates the two ways that happens: the derived figure changing as work below it moved, or a
 * human overriding it with a reason ([D-P6-3]). A listener that notifies a client cares about the
 * difference; one that busts a cache does not.
 */
final class ProjectProgressChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly string $from,
        public readonly string $to,
        public readonly bool $manual = false,
        public readonly ?int $actorId = null,
    ) {}
}
