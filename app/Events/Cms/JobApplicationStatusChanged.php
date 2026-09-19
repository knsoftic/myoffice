<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Enums\JobApplicationStatus;
use App\Models\Cms\JobApplication;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A candidate moved along the six-stage pipeline (phase-04 §6.8 `changeStatus()`, §10.1).
 *
 * Carries the stage it left, the stage it entered, the reason (required for `rejected`) and the interview
 * slot when the new stage is `interview`. Listener: `LogApplicationStage`, which writes the activity entry
 * with the old and new stage, the reason and the slot — the source of the detail screen's timeline.
 */
final class JobApplicationStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{interview_at?: string|null, interview_mode?: string|null, interview_location?: string|null}  $interview
     * @param  int|null  $actorId  `users.id` of whoever moved the candidate (null from the console)
     */
    public function __construct(
        public readonly JobApplication $application,
        public readonly JobApplicationStatus $from,
        public readonly JobApplicationStatus $to,
        public readonly ?string $reason = null,
        public readonly array $interview = [],
        public readonly ?int $actorId = null,
    ) {}
}
