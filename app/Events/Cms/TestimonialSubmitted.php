<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Contracts\Cms\Moderatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A testimonial or student review arrived from a source that needs moderation — anything but `admin` or
 * `import` (phase-04 §10.1, `ContentSource::requiresModeration()`).
 *
 * The record is always `pending` at this point, whatever `website.testimonial_auto_approve` says.
 * Listener: `NotifyStaffOfPendingModeration` (queued).
 */
final class TestimonialSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Moderatable&Model $record,
    ) {}
}
