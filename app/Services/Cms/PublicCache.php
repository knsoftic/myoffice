<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Events\SettingsChanged;
use App\Support\SettingsRegistry;
use Closure;

/**
 * `App\Services\Cms\PublicCache` — the name phase-03 §6.7 and INV-8 give the public cache, and the one
 * later contracts call (`PublicCache::bump()` in phase-24-25's deploy step and PRF-08, the course-publish
 * hook of phase-14-17). A thin front over `CacheVersion`, which implements D22's version stamp:
 *
 *   app(PublicCache::class)->bump('Course #12 published');            // now
 *   app(PublicCache::class)->bumpAfterCommit('Course #12 published'); // inside a transaction
 *
 * The request-keyed half of §6.7 (`key(Request)`, `remember(Request, Closure)`) lives in the `site.cache`
 * middleware (`App\Http\Middleware\CachePublicResponse`); a later phase never keys pages itself.
 *
 * It also invalidates the site when a setting the public pages embed is saved (`SettingsChanged`):
 * company, contact, branding and social details, SEO and robots switches, website options, number
 * formats and the maintenance switches. Pages are cached for `website.cache_ttl_minutes` (a day by
 * default), so without this an administrator who turns indexing off would keep serving `index, follow`
 * pages until the TTL ran out.
 */
final class PublicCache
{
    /**
     * Settings groups the public website reads, public or not (the number separators are not public
     * settings, but every statistic on the home page is formatted with them).
     *
     * @var list<string>
     */
    public const SITE_SETTING_GROUPS = [
        'appearance', 'branding', 'company', 'contact', 'localization', 'maintenance', 'seo', 'social', 'website',
    ];

    public function __construct(
        private readonly CacheVersion $version,
    ) {}

    public function version(): int
    {
        return $this->version->version();
    }

    /**
     * Invalidate every public page, menu tree, statistic block and sitemap at once. Returns the new version.
     */
    public function bump(string $reason): int
    {
        return $this->version->bump($reason);
    }

    /**
     * Bump once the surrounding transaction commits (immediately when there is none); never for a
     * transaction that rolls back.
     */
    public function bumpAfterCommit(string $reason): void
    {
        $this->version->bumpAfterCommit($reason);
    }

    /**
     * §6.7 `flush()`: an alias of `bump()` — there is nothing to delete.
     */
    public function flush(string $reason = 'Public cache flushed'): int
    {
        return $this->version->flush($reason);
    }

    /**
     * Run a unit of work in which every bump collapses into one.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function batch(Closure $callback, string $reason): mixed
    {
        return $this->version->batch($callback, $reason);
    }

    /**
     * The `SettingsChanged` listener: one bump per save that touches something a public page shows.
     */
    public function settingsChanged(SettingsChanged $event): void
    {
        $visible = array_values(array_filter(
            $event->keys,
            static fn (string $key): bool => in_array(explode('.', $key, 2)[0], self::SITE_SETTING_GROUPS, true)
                || in_array($key, SettingsRegistry::publicKeys(), true),
        ));

        if ($visible === []) {
            return;
        }

        $this->version->bumpAfterCommit(sprintf('Settings changed: %s', implode(', ', array_slice($visible, 0, 10))));
    }
}
