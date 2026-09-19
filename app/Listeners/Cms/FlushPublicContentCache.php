<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\BlogPostPublished;
use App\Events\Cms\StudentReviewApproved;
use App\Events\Cms\TestimonialApproved;
use App\Services\Cms\CacheVersion;
use Throwable;

/**
 * Invalidate the public page cache when approved or published content appears (phase-04 §10.1).
 *
 * Listens to `TestimonialApproved`, `StudentReviewApproved` and `BlogPostPublished`. The public cache is
 * invalidated by Phase 3's version stamp (D22) — there is nothing to delete, only `CacheVersion::bump()`.
 * Runs after the write committed; a cache failure is reported and never undoes the approval or the
 * publication.
 */
final class FlushPublicContentCache
{
    public function __construct(
        private readonly CacheVersion $cache,
    ) {}

    public function handle(TestimonialApproved|StudentReviewApproved|BlogPostPublished $event): void
    {
        $reason = match (true) {
            $event instanceof TestimonialApproved => sprintf('Testimonial #%d approved', (int) $event->testimonial->getKey()),
            $event instanceof StudentReviewApproved => sprintf('Student review #%d approved', (int) $event->review->getKey()),
            default => sprintf(
                'Blog post "%s" published%s',
                mb_substr((string) $event->post->getAttribute('title'), 0, 120),
                $event->viaScheduler ? ' on schedule' : '',
            ),
        };

        try {
            $this->cache->bump($reason);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
