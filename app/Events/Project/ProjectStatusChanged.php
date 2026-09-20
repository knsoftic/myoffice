<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Project;
use App\Enums\ProjectStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project moved between the eight statuses of §20 (phase-06 §2.13.1).
 *
 * Carries both ends so a listener never has to guess, and the reason where §2.13.1 marks one mandatory —
 * pausing, cancelling and every reopen.
 */
final class ProjectStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectStatus $from,
        public readonly ProjectStatus $to,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
