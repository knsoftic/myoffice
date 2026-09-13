<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Services\Cms\CacheVersion;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The overview's "Flush" button (`admin.website.cache.flush`, `can:website_sections.change_status`,
 * `throttle:6,1`, §8.3).
 *
 * There is nothing to delete: the public cache is invalidated by incrementing its version stamp, O(1)
 * on the database store (D22, INV-8). `CacheVersion::flush()` writes the audit row itself.
 */
final class PublicCacheController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly CacheVersion $cache,
    ) {}

    public function flush(Request $request): Response
    {
        $this->authorize('website_sections.change_status');

        $version = $this->cache->flush(sprintf('Flushed from the Website overview by %s', $this->actor($request)->name));

        return $this->done(
            $request,
            sprintf('Public cache cleared. Visitors get freshly rendered pages (cache version %d).', $version),
            null,
            ['version' => $version],
        );
    }
}
