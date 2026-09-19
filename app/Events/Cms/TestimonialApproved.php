<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Models\Cms\Testimonial;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A testimonial passed moderation and is now public (phase-04 §6.5 invariant 2, §10.1).
 *
 * Listener: `FlushPublicContentCache` — the testimonial wall is a cached public section.
 */
final class TestimonialApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Testimonial $testimonial,
    ) {}
}
