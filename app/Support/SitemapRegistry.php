<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Cms\SitemapUrlProvider;
use InvalidArgumentException;

/**
 * The registry of `sitemap.xml` URL providers named by phase-03 §6.5 (and called by phase-04 §13 and
 * phase-14-17): `register(string $key, SitemapUrlProvider $p)` / `providers(): array`.
 *
 *   // a later phase's service provider, boot():
 *   SitemapRegistry::register('services', app(ServiceSitemapProvider::class));
 *
 * `App\Services\Cms\SitemapGenerator` reads `providers()` on every build, next to its own
 * `SitemapGenerator::extend()` list, so a phase registers here and never edits the generator. The key
 * recorded in `sitemap_generations.providers` is the provider's own `key()`.
 *
 * Invariants:
 *
 *   · `pages` and `static` are Phase 3's own entry sets (`SeoService::sitemapEntries()`) and are refused.
 *   · A key is registered once; a second registration under the same key is a programming error, not a
 *     silent replacement of another phase's URLs.
 *   · Process-wide and in memory: registration belongs in `boot()`, which runs on every request.
 */
final class SitemapRegistry
{
    /** Entry sets Phase 3 owns; no provider may take these keys. */
    public const RESERVED_KEYS = ['pages', 'static'];

    /** @var array<string, SitemapUrlProvider> */
    private static array $providers = [];

    public static function register(string $key, SitemapUrlProvider $provider): void
    {
        $key = trim($key);

        if ($key === '' || in_array($key, self::RESERVED_KEYS, true)) {
            throw new InvalidArgumentException(sprintf('[%s] cannot be used as a sitemap provider key.', $key));
        }

        if (isset(self::$providers[$key]) && self::$providers[$key] !== $provider) {
            throw new InvalidArgumentException(sprintf('A sitemap provider is already registered under [%s].', $key));
        }

        self::$providers[$key] = $provider;
    }

    /**
     * @return array<string, SitemapUrlProvider>
     */
    public static function providers(): array
    {
        return self::$providers;
    }

    public static function has(string $key): bool
    {
        return isset(self::$providers[$key]);
    }

    /**
     * Forget every registration. For tests.
     */
    public static function flush(): void
    {
        self::$providers = [];
    }
}
