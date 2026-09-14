<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Cms\CacheVersion;
use App\Support\Cms\PublicOrigin;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Alias: `site.cache` — the full-page public cache (phase-03 §6.7, INV-8, INV-9, R-4; decision D22;
 * integration A.2).
 *
 *   Route::get('/', HomeController::class)->middleware(['site', 'site.preview', 'site.cache']);
 *
 * A terminable middleware. An anonymous GET is answered from a stored copy when one exists; otherwise
 * the page renders, and the copy is written in `terminate()`, after the response has been sent.
 *
 * **Key.** `CacheVersion::key('page', [scheme, host, base path + path, locale, whitelisted query])`. The
 * version stamp is read **before** the page renders, so a publish landing mid-render stores the old HTML
 * under the old, already unreachable, version — never under the new one. Any publish makes every stored
 * page unreachable at once (INV-8).
 *
 * **Query whitelist.** Only `page`, `category` and `ref`, each with a strict value pattern. `ref` (the §38
 * referral code, phase-08-09's `[A-Z0-9][A-Z0-9-]{3,31}`) is whitelisted on every cached route **and
 * keyed**, so one visitor's `?ref=COL-1024` page is never served to a visitor without it. `page` and
 * `category` are keyed only on a route that declares it reads them —
 *
 *   Route::get('blog', ...)->middleware(['site', 'site.cache:page,category']);
 *
 * — because on any other route they cannot change the page, and keying them there would let anyone mint a
 * stored copy per value. Any other key, an undeclared `page` / `category`, an array value or a value
 * outside its pattern bypasses the cache.
 *
 * **Storage is bounded** (review round 2). Only the application's own host (`PublicOrigin::servesHost()`:
 * `config('app.url')` and `seo.canonical_base_url`) reads or writes the cache, so a spoofed `Host` header
 * mints nothing; at most `MAX_QUERY_VARIANTS` query-string variants are stored per cache version (a plain
 * path is bounded by the pages that answer 200 and is never capped); and `cms:cache-prune` deletes the
 * expired rows the database store would otherwise keep forever.
 *
 * **Bypass** — the page renders normally, is not served from the cache, and is not stored — when:
 *
 *   · the request is not a GET (HEAD included);
 *   · the request's host is not the public site's own (`PublicOrigin::servesHost()`);
 *   · the user is authenticated;
 *   · the request is a preview (`ResolvePreviewMode::isPreview()`, INV-9);
 *   · `website.cache_enabled` is off;
 *   · the query string carries a key outside the whitelist, a `page` / `category` the route does not
 *     declare, or a whitelisted key with an unsafe value;
 *   · the session holds flash data (a toast, validation errors, old input) — before or after the render;
 *   · the response is not a 200, has no string body, or says `Cache-Control: no-store`;
 *   · the response carries a `Set-Cookie`, or a cookie was queued while it rendered, other than the session
 *     and XSRF cookies;
 *   · the rendered body contains the session's CSRF token (a personalised page, R-4).
 *
 * **What is stored: the body, the status, `Content-Type`, `X-Robots-Tag`, `X-Content-Type-Options` and the
 * `ETag` — never a `Set-Cookie`.** The session and XSRF cookies are added later by the outer `web`
 * middleware, so a replay passes back through them and carries **that** visitor's own cookies. Do not
 * "bypass when a cookie is set": the session cookie is on every response and nothing would ever be cached.
 *
 * **Served with** an `ETag` derived from the cache key and the body, and `Cache-Control: public,
 * max-age=300`. A matching `If-None-Match` on a stored page answers 304.
 *
 * R-4: this cache assumes a public page has no per-visitor content. Anything personalised that is ever
 * added to a public page must bypass this middleware or be keyed into it.
 *
 * A cache store failure is reported and the page renders; it never turns a working page into a 500.
 */
final class CachePublicResponse
{
    /** The query keys that may reach a cached page, each with the only value shape that is keyed. */
    public const QUERY_WHITELIST = [
        'page' => '/^[1-9][0-9]{0,3}$/',
        'category' => '/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/',
        'ref' => '/^[A-Za-z0-9][A-Za-z0-9-]{3,31}$/',
    ];

    /** Whitelisted keys that are keyed on every cached route; the others only where the route declares them. */
    public const ALWAYS_KEYED = ['ref'];

    /** Query-string variants stored per cache version, across every route; past it a variant still renders. */
    public const MAX_QUERY_VARIANTS = 500;

    /** The `CacheVersion` namespace every stored page lives under. */
    public const CACHE_NAMESPACE = 'page';

    /** The `CacheVersion` namespace of the per-version variant counter. */
    public const VARIANT_COUNTER_NAMESPACE = 'page-variants';

    /** What a browser (or proxy) may do with a cacheable public page (§6.7). */
    public const BROWSER_CACHE_CONTROL = 'public, max-age=300';

    /** The response headers a stored copy keeps. Never a cookie. */
    private const STORED_HEADERS = ['Content-Type', 'X-Robots-Tag', 'X-Content-Type-Options'];

    /** Request attribute carrying the copy `terminate()` writes. */
    private const PENDING_ATTRIBUTE = 'cms_cache_pending';

    /** Bump when the stored payload's shape changes; an old shape then reads as a miss. */
    private const PAYLOAD_FORMAT = 1;

    /** Laravel's XSRF cookie, added by `ValidateCsrfToken` outside this middleware. */
    private const XSRF_COOKIE = 'XSRF-TOKEN';

    /** `website.cache_ttl_minutes` bounds (phase-03 §5.1a). */
    private const DEFAULT_TTL_MINUTES = 1440;

    private const MAX_TTL_MINUTES = 10_080;

    public function __construct(
        private readonly CacheVersion $version,
        private readonly CacheRepository $cache,
        private readonly CookieJar $cookies,
    ) {}

    /**
     * @param  string  ...$queryKeys  the whitelisted keys beyond `ALWAYS_KEYED` this route reads (`page`, `category`)
     */
    public function handle(Request $request, Closure $next, string ...$queryKeys): Response
    {
        $parts = $this->cacheableParts($request, $queryKeys);

        if ($parts === null) {
            return $next($request);
        }

        // Read the stamp before rendering: see the class docblock.
        $key = $this->version->key(self::CACHE_NAMESPACE, $parts);
        $variantCounter = $parts[4] === [] ? null : $this->version->key(self::VARIANT_COUNTER_NAMESPACE, ['count']);

        $stored = $this->read($key);

        if ($stored !== null) {
            return $this->replay($request, $stored);
        }

        $queuedBefore = $this->queuedCookieNames();

        $response = $next($request);

        if (! $this->storable($request, $response, $queuedBefore)) {
            return $response;
        }

        $body = (string) $response->getContent();
        $etag = $this->etag($key, $body);

        $headers = [];

        foreach (self::STORED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', self::BROWSER_CACHE_CONTROL);

        $request->attributes->set(self::PENDING_ATTRIBUTE, [
            'key' => $key,
            'variant_counter' => $variantCounter,
            'seconds' => $this->ttlSeconds(),
            'payload' => [
                'format' => self::PAYLOAD_FORMAT,
                'status' => Response::HTTP_OK,
                'body' => $body,
                'headers' => $headers,
                'etag' => $etag,
            ],
        ]);

        return $response;
    }

    /**
     * Write the copy `handle()` prepared, once the response is on its way.
     */
    public function terminate(Request $request, Response $response): void
    {
        $pending = $request->attributes->get(self::PENDING_ATTRIBUTE);
        $request->attributes->remove(self::PENDING_ATTRIBUTE);

        if (! is_array($pending) || ! is_string($pending['key'] ?? null) || ! is_array($pending['payload'] ?? null)) {
            return;
        }

        // An outer middleware turned the page into something else (an error, a redirect): keep nothing.
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return;
        }

        $seconds = max(60, (int) ($pending['seconds'] ?? 60));

        try {
            if (is_string($pending['variant_counter'] ?? null) && ! $this->claimVariantSlot($pending['variant_counter'], $seconds)) {
                return;
            }

            $this->cache->put($pending['key'], $pending['payload'], $seconds);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The key parts of a request that may be served from (and stored into) the cache, or null to bypass.
     *
     * @param  list<string>  $declared  the route's `site.cache:` parameters
     * @return array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>}|null
     */
    private function cacheableParts(Request $request, array $declared): ?array
    {
        if (! $request->isMethod('GET')) {
            return null;
        }

        // A client-chosen Host never reads or writes the shared copy: see "Storage is bounded".
        if (! PublicOrigin::servesHost($request)) {
            return null;
        }

        if ($request->user() !== null) {
            return null;
        }

        if (ResolvePreviewMode::isPreview($request)) {
            return null;
        }

        if (! (bool) setting('website.cache_enabled', true)) {
            return null;
        }

        if ($this->sessionHasFlash($request)) {
            return null;
        }

        $query = [];

        foreach ($request->query->all() as $name => $value) {
            $pattern = self::QUERY_WHITELIST[$name] ?? null;

            if ($pattern === null || ! is_string($value) || preg_match($pattern, $value) !== 1) {
                return null;
            }

            if (! in_array($name, self::ALWAYS_KEYED, true) && ! in_array($name, $declared, true)) {
                return null;
            }

            $query[$name] = $value;
        }

        ksort($query);

        return [
            $request->getScheme(),
            strtolower($request->getHttpHost()),
            $request->getBaseUrl().'/'.trim($request->path(), '/'),
            app()->getLocale(),
            $query,
        ];
    }

    /**
     * May the response just rendered be stored? Re-asks the request-side questions too: a render can
     * flash a message or mark a preview.
     *
     * @param  array<string, true>  $queuedBefore
     */
    private function storable(Request $request, Response $response, array $queuedBefore): bool
    {
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return false;
        }

        $body = $response->getContent();

        if (! is_string($body)) {
            return false;
        }

        if ($response->headers->hasCacheControlDirective('no-store')) {
            return false;
        }

        if ($request->user() !== null || ResolvePreviewMode::isPreview($request) || $this->sessionHasFlash($request)) {
            return false;
        }

        $ignored = [(string) config('session.cookie'), self::XSRF_COOKIE];

        foreach ($response->headers->getCookies() as $cookie) {
            if (! in_array($cookie->getName(), $ignored, true)) {
                return false;
            }
        }

        // A cookie queued while the page rendered would be lost on a replay. One queued before this
        // middleware ran (an outer middleware) is re-queued on every request, so it does not count.
        foreach (array_keys($this->queuedCookieNames()) as $queued) {
            if (! isset($queuedBefore[$queued]) && ! in_array(explode('|', $queued, 2)[0], $ignored, true)) {
                return false;
            }
        }

        // R-4: the session's CSRF token in the body means the page is personalised (a form). Storing it
        // would hand one visitor's token to every other visitor, whose submissions would then fail.
        if ($request->hasSession()) {
            $token = $request->session()->token();

            if (is_string($token) && $token !== '' && str_contains($body, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A stored copy, or null for a miss (absent, unreadable, or not the shape this class writes).
     *
     * @return array{body: string, headers: array<string, string>, etag: string}|null
     */
    private function read(string $key): ?array
    {
        try {
            $stored = $this->cache->get($key);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($stored)
            || ($stored['format'] ?? null) !== self::PAYLOAD_FORMAT
            || ($stored['status'] ?? null) !== Response::HTTP_OK
            || ! is_string($stored['body'] ?? null)
            || ! is_string($stored['etag'] ?? null)
            || ! is_array($stored['headers'] ?? null)) {
            return null;
        }

        $headers = [];

        foreach ($stored['headers'] as $name => $value) {
            if (in_array($name, self::STORED_HEADERS, true) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return ['body' => $stored['body'], 'headers' => $headers, 'etag' => $stored['etag']];
    }

    /**
     * Build the response for a stored copy. It still travels back through the outer `web` middleware,
     * which adds this visitor's own session and XSRF cookies.
     *
     * @param  array{body: string, headers: array<string, string>, etag: string}  $stored
     */
    private function replay(Request $request, array $stored): Response
    {
        $response = new IlluminateResponse($stored['body'], Response::HTTP_OK, $stored['headers'] + [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);

        $response->headers->set('ETag', $stored['etag']);
        $response->headers->set('Cache-Control', self::BROWSER_CACHE_CONTROL);

        // A matching If-None-Match turns this into a 304 with no body.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Count one more stored query-string variant for this cache version; false once `MAX_QUERY_VARIANTS`
     * have been stored, so a flood of distinct `?ref=` values renders but writes nothing. The counter lives
     * as long as the pages it counts and dies with the version, like them.
     */
    private function claimVariantSlot(string $counterKey, int $seconds): bool
    {
        $this->cache->add($counterKey, 0, $seconds);
        $count = $this->cache->increment($counterKey);

        return is_numeric($count) && (int) $count <= self::MAX_QUERY_VARIANTS;
    }

    private function sessionHasFlash(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return (array) $session->get('_flash.old', []) !== []
            || (array) $session->get('_flash.new', []) !== [];
    }

    /**
     * The cookies queued on the jar so far, as a set of `name|path`.
     *
     * @return array<string, true>
     */
    private function queuedCookieNames(): array
    {
        $names = [];

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            if ($cookie instanceof Cookie) {
                $names[$cookie->getName().'|'.$cookie->getPath()] = true;
            }
        }

        return $names;
    }

    /**
     * A strong ETag from the cache key and the body: the key alone would repeat across a TTL expiry
     * whose re-render differs (a live statistic), and a browser would keep the older page on a 304.
     */
    private function etag(string $key, string $body): string
    {
        return '"'.sha1($key."\n".sha1($body)).'"';
    }

    private function ttlSeconds(): int
    {
        $minutes = setting('website.cache_ttl_minutes', self::DEFAULT_TTL_MINUTES);
        $minutes = is_numeric($minutes) ? (int) $minutes : self::DEFAULT_TTL_MINUTES;

        return max(1, min(self::MAX_TTL_MINUTES, $minutes)) * 60;
    }
}
