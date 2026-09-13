<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Cms\SeoService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `robots.txt` — `site.robots` (phase-03 §6.5, §7.6, FT-47).
 *
 * Deliberately **outside** every gate: a crawler must be able to read "stay away" while the site is in
 * maintenance or switched off ([D-W3-13]). The body is `SeoService::robotsTxt()` — `auto` generation,
 * the stored `custom` text, or `Disallow: /` whenever the site is not indexable, in maintenance or off
 * (the strictest wins).
 */
final class RobotsController extends Controller
{
    public function __construct(
        private readonly SeoService $seo,
    ) {}

    public function __invoke(): Response
    {
        return response($this->seo->robotsTxt(), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            // Short, so switching maintenance on reaches crawlers quickly.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
