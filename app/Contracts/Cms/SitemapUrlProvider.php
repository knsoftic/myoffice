<?php

declare(strict_types=1);

namespace App\Contracts\Cms;

use App\Services\Cms\Data\SitemapEntry;

/**
 * One set of public URLs for `sitemap.xml` (phase-03 §6.5), registered with
 * `App\Support\SitemapRegistry::register($provider->key(), $provider)` from a service provider's `boot()`.
 *
 * Later phases (services, portfolio, blog, courses, careers) add their URLs this way and **never edit
 * `SitemapGenerator`**. A provider lists only what an anonymous visitor may open: published, public,
 * indexable rows — never a draft, a preview URL or anything behind authentication.
 */
interface SitemapUrlProvider
{
    /**
     * The provider's name in `sitemap_generations.providers` (`services`, `blog`, ...). `pages` and
     * `static` are Phase 3's own and may not be taken.
     */
    public function key(): string;

    /**
     * `SitemapEntry` objects, or arrays of `loc` (absolute URL), `lastmod`, `changefreq`, `priority`.
     *
     * @return iterable<int, SitemapEntry|array<string, mixed>>
     */
    public function urls(): iterable;
}
