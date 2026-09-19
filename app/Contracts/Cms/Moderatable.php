<?php

declare(strict_types=1);

namespace App\Contracts\Cms;

use App\Enums\ApprovalStatus;

/**
 * A record that must pass the moderation queue before it reaches the public site (phase-04 §6.5):
 * `App\Models\Cms\Testimonial` and `App\Models\Cms\StudentReview`.
 *
 * One code path moderates both: `ModerationService` type-hints `Moderatable&Model` and never branches on
 * the concrete class for the transition rules. The review body is never modified by moderation.
 */
interface Moderatable
{
    /**
     * The current moderation state (`status` column).
     */
    public function moderationStatus(): ApprovalStatus;

    /**
     * Would the public site render this record right now (approved and not trashed)?
     */
    public function isPubliclyVisible(): bool;

    /**
     * A short human label for toasts and activity descriptions — "Testimonial from Ayesha Khan".
     */
    public function moderationLabel(): string;
}
