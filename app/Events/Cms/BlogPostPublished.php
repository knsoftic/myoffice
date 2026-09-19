<?php

declare(strict_types=1);

namespace App\Events\Cms;

use App\Models\Cms\BlogPost;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A blog post went live (phase-04 §6.7 invariant 2, §6.7.3, §10.1).
 *
 * Fired exactly once per transition into `published` — by `BlogService::publish()` (`$viaScheduler =
 * false`) or by `blog:publish-scheduled` (`true`) — and never by a plain edit of a post that is already
 * published. Listeners: `NotifyAuthorOfPublication` (queued, scheduler publications only),
 * `FlushPublicContentCache`, `PingSitemap`.
 */
final class BlogPostPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly BlogPost $post,
        public readonly bool $viaScheduler = false,
    ) {}
}
