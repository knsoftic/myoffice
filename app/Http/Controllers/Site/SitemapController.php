<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Cms\SitemapGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sitemap.xml` and its chunks — `site.sitemap`, `site.sitemap.chunk` (phase-03 §6.5, §7.6, FT-46).
 *
 * The XML comes from `SitemapGenerator::cached()` under the version-stamped cache: any publish makes the
 * next request rebuild it. A disabled sitemap (`seo.sitemap_enabled = false`) is a 404, and so is a
 * chunk that does not exist — `/sitemap-{n}.xml` exists only once the URL set is split at 40 000 URLs,
 * and n starts at 1.
 */
final class SitemapController extends Controller
{
    public function __construct(
        private readonly SitemapGenerator $sitemap,
    ) {}

    public function index(): Response
    {
        $xml = $this->sitemap->cached();

        abort_if($xml === null, Response::HTTP_NOT_FOUND);

        return $this->xml($xml);
    }

    public function chunk(string $index): Response
    {
        // `whereNumber` guarantees digits; 0 and absurd lengths are not chunks (the chunk cache key of 0
        // is the index's own, so it must never be asked for).
        abort_if(strlen($index) > 6 || (int) $index < 1, Response::HTTP_NOT_FOUND);

        $xml = $this->sitemap->cached((int) $index);

        abort_if($xml === null, Response::HTTP_NOT_FOUND);

        return $this->xml($xml);
    }

    private function xml(string $xml): Response
    {
        return response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            // A sitemap is for crawlers to read, not a page to index.
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
