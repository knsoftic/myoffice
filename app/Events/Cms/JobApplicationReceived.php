<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Models\Cms\JobApplication;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A candidate applied through the public careers form (phase-04 §6.8 `apply()` invariant 7, §10.1).
 *
 * Fired after commit, so the row and its CV exist. Listener: `NotifyHrOfApplication` (queued) — the CV is
 * never attached to any email.
 */
final class JobApplicationReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly JobApplication $application,
    ) {}
}
