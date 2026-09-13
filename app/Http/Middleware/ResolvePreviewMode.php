<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: `site.preview` (phase-03 §6.10, §6.12, INV-9; integration A.2).
 *
 *   Route::get('/', HomeController::class)->middleware(['site', 'site.preview', 'site.cache']);
 *
 * Marks a request that is **shaped like a preview**, so nothing downstream caches or indexes it. A request
 * is preview-shaped when any of these is present:
 *
 *   · the query flag `?preview=1` (a staff member previewing a live URL);
 *   · a `signature` query parameter (a shareable `URL::temporarySignedRoute` link);
 *   · a `site.preview.*` route (`/preview/page/{page}`, `/preview/section/{section}`).
 *
 * What this middleware is **not**:
 *
 *   · **not authorisation.** The mark says "treat this as a preview", never "this visitor may see drafts".
 *     `Site\PreviewController` checks the signature or the `pages.view` / `website_sections.view`
 *     permission, and `ComposesSite::previewRequested()` checks the permission for `?preview=1`. A guest
 *     who appends `?preview=1` is marked and still gets the live page;
 *   · **not a renderer.** It never selects, composes or renders draft content on its own.
 *
 * On the way out a marked response is forced to `Cache-Control: no-store, private` and
 * `X-Robots-Tag: noindex, nofollow`. The controllers already send both (INV-9); this is the safety net that
 * keeps the rule true for a response a controller forgot to decorate. It only ever tightens: nothing a
 * marked request returns can be stored by a browser, a proxy or `site.cache`, or indexed by a crawler.
 */
final class ResolvePreviewMode
{
    /** The request attribute `CachePublicResponse` (and anything else) reads. */
    public const ATTRIBUTE = 'cms_preview';

    /** `?preview=1`, the same flag `ComposesSite::previewRequested()` reads. */
    public const QUERY_FLAG = 'preview';

    /** The query parameter Laravel's signed URLs carry. */
    public const SIGNATURE_PARAMETER = 'signature';

    /** The preview routes of phase-03 §7.6. */
    public const ROUTE_PATTERN = 'site.preview.*';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::detect($request)) {
            $request->attributes->set(self::ATTRIBUTE, true);
        }

        $response = $next($request);

        if (self::isPreview($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * Is this request a preview — marked by this middleware, or preview-shaped on its own?
     *
     * The second half lets a route that does not carry `site.preview` (or carries it after `site.cache`)
     * still be recognised, so the cache can never store a preview because of middleware order.
     */
    public static function isPreview(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true || self::detect($request);
    }

    /**
     * The three preview signals, read from the request alone.
     */
    public static function detect(Request $request): bool
    {
        if ($request->query(self::QUERY_FLAG) === '1') {
            return true;
        }

        if ($request->query->has(self::SIGNATURE_PARAMETER)) {
            return true;
        }

        return $request->route()?->named(self::ROUTE_PATTERN) === true;
    }
}
