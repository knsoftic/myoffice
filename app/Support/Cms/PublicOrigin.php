<?php

declare(strict_types=1);

namespace App\Support\Cms;

use Illuminate\Http\Request;
use Throwable;

/**
 * Where the public website lives — the origin every absolute public URL is built from and the only hosts
 * a stored public page may be keyed under (phase-03 §6.5, §6.7, D22; review round 2).
 *
 *   PublicOrigin::baseUrl();                 // "https://www.example.com" — never the request's Host
 *   PublicOrigin::servesHost($request);      // may this request's Host read or write the page cache?
 *   PublicOrigin::trustedHostPatterns();     // the extra pattern TrustHosts accepts (canonical host)
 *
 * The `Host` header is chosen by the client. Anything that is cached for every visitor (the sitemap, a
 * stored page) must therefore never be built from it or keyed by an arbitrary value of it:
 *
 *   · `baseUrl()` is `seo.canonical_base_url` when it is an http(s) URL, else `config('app.url')` — the same
 *     chain phase-08-09's `referralUrl()` uses — so a sitemap rebuilt by a request carrying
 *     `Host: evil.test` is byte-identical to the one the scheduler builds.
 *   · `servesHost()` accepts exactly the host (and non-default port) of those two URLs. Any other Host still
 *     gets its page, rendered, but never from and never into the shared cache, so varying the header cannot
 *     mint cache rows.
 *
 * A settings store that cannot be read degrades to `config('app.url')` alone; it never throws into a request.
 */
final class PublicOrigin
{
    /** Used only when `config('app.url')` is not an http(s) URL either. */
    private const FALLBACK = 'http://localhost';

    /**
     * The public base URL without a trailing slash.
     */
    public static function baseUrl(): string
    {
        return self::canonicalBaseUrl() ?? self::applicationUrl();
    }

    /**
     * `seo.canonical_base_url` without a trailing slash, or null when it is empty or not an http(s) URL.
     */
    public static function canonicalBaseUrl(): ?string
    {
        try {
            $value = setting('seo.canonical_base_url');
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return self::normalise($value);
    }

    /**
     * `config('app.url')` without a trailing slash.
     */
    public static function applicationUrl(): string
    {
        return self::normalise(config('app.url')) ?? self::FALLBACK;
    }

    /**
     * The `host[:port]` values the public site is served on, lower-case, in the form
     * `Request::getHttpHost()` reports them (a default port is omitted).
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        $hosts = [];

        foreach ([self::applicationUrl(), self::canonicalBaseUrl()] as $url) {
            $host = $url === null ? null : self::httpHost($url);

            if ($host !== null && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * May this request read from, or write to, the shared public page cache?
     */
    public static function servesHost(Request $request): bool
    {
        return in_array(strtolower($request->getHttpHost()), self::hosts(), true);
    }

    /**
     * The extra `TrustHosts` pattern for `seo.canonical_base_url` (the application URL and its subdomains are
     * added by the middleware itself). Only consulted outside `local` and outside the test runner.
     *
     * @return list<string>
     */
    public static function trustedHostPatterns(): array
    {
        $canonical = self::canonicalBaseUrl();
        $host = $canonical === null ? null : parse_url($canonical, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? ['^'.preg_quote(strtolower($host)).'$'] : [];
    }

    private static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = rtrim(trim($value), '/');

        if (preg_match('~^https?://[^/?#\s]+~i', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private static function httpHost(string $url): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $default = $scheme === 'https' ? 443 : 80;

        return strtolower($host).($port !== null && $port !== $default ? ':'.$port : '');
    }
}
