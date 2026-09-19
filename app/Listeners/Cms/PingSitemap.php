<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Events\Cms\BlogPostPublished;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Rebuild the sitemap after a post goes live (phase-04 §10.1) — queued.
 *
 * A no-op until Phase 3's `App\Support\SitemapRegistry` exists (the contract's words): before that the
 * blog has no provider in the sitemap, and the cached sitemap is already invalidated by the version bump
 * `FlushPublicContentCache` performs. Once the registry exists, a publication triggers
 * `SitemapGenerator::regenerate('publish')`, at most once a minute — `blog:publish-scheduled` clearing a
 * backlog of posts rebuilds once, not once per post.
 */
final class PingSitemap implements ShouldQueue
{
    private const REGISTRY = 'App\\Support\\SitemapRegistry';

    private const THROTTLE_KEY = 'cms:sitemap-ping';

    private const THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function handle(BlogPostPublished $event): void
    {
        if (! class_exists(self::REGISTRY) || ! class_exists(SitemapGenerator::class)) {
            return;
        }

        try {
            if (! $this->cache->add(self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS)) {
                return;
            }

            app(SitemapGenerator::class)->regenerate('publish');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
