<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Models\Cms\StudentReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student review passed moderation and is now public (phase-04 §6.5 invariant 2, §10.1).
 *
 * Listener: `FlushPublicContentCache`.
 */
final class StudentReviewApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly StudentReview $review,
    ) {}
}
