<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Cms\SitemapGeneration;
use App\Models\User;
use App\Services\Cms\Data\SitemapEntry;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * `sitemap.xml` — built, cached under the D22 version stamp, and logged (phase-03 §6.5 `SitemapService`,
 * §2.14 `sitemap_generations`, §105 "sitemap support", FT-46).
 *
 * One public entry point per operation:
 *
 *   urls()        every entry: Phase 3's (via `SeoService::sitemapEntries()`) plus every registered provider
 *   generate()    the XML — a `<urlset>`, or above 40 000 URLs a `<sitemapindex>` of `/sitemap-{n}.xml`
 *   cached()      `generate()` behind the version-stamped cache (24 h); what the public route serves
 *   regenerate()  rebuild now, refresh the cache and append a `sitemap_generations` row either way
 *   extend()      register a URL provider directly (later phases use `App\Support\SitemapRegistry::register()`,
 *                 which this class reads too)
 *
 * Invariants:
 *
 *   · **Disabled means absent.** With `seo.sitemap_enabled` off, `generate()` and `cached()` return
 *     null and the route 404s; with `seo.robots_indexable` off, the sitemap lists nothing.
 *   · **Later phases add URLs without editing this class** (§6.5): a provider is any object with
 *     `urls(): iterable` (of `SitemapEntry` or `loc/lastmod/changefreq/priority` arrays) and,
 *     optionally, `key(): string`. A provider that throws is reported and skipped — one phase's bug
 *     cannot take the whole sitemap down — and its failure is named in the generation row.
 *   · **Well-formed output.** UTF-8, XML-escaped, absolute `loc`s only, duplicates removed, no
 *     trailing whitespace. The per-provider URL counts are recorded in `providers`.
 *   · A generation row is append-only: this class inserts it and never updates or deletes one.
 */
final class SitemapGenerator
{
    public const MAX_URLS_PER_FILE = 40_000;

    public const CACHE_SECONDS = 86_400;

    public const NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** @var list<string> */
    public const TRIGGERS = ['manual', 'publish', 'scheduled'];

    /** The contract's registry (§6.5), `App\Support\SitemapRegistry`. */
    private const REGISTRY = 'App\\Support\\SitemapRegistry';

    /** @var array<string, object> */
    private static array $extensions = [];

    /** @var array<string, string> provider key => failure message, for the last `urls()` call */
    private array $failures = [];

    public function __construct(
        private readonly SeoService $seo,
        private readonly CacheVersion $version,
        private readonly CacheRepository $cache,
        private readonly SettingsRepository $settings,
        private readonly DatabaseManager $db,
        private readonly CmsAuditor $auditor,
    ) {}

    /**
     * Register a URL provider from a service provider's `boot()`.
     */
    public static function extend(string $key, object $provider): void
    {
        if (! method_exists($provider, 'urls')) {
            throw new InvalidArgumentException(sprintf('Sitemap provider [%s] must implement urls(): iterable.', $key));
        }

        self::$extensions[$key] = $provider;
    }

    /**
     * Forget every `extend()` registration. For tests.
     */
    public static function flushExtensions(): void
    {
        self::$extensions = [];
    }

    public function isEnabled(): bool
    {
        return $this->bool('seo.sitemap_enabled', true);
    }

    /**
     * @return Collection<int, SitemapEntry>
     */
    public function urls(): Collection
    {
        $this->failures = [];

        if (! $this->bool('seo.robots_indexable', true)) {
            return collect();
        }

        $entries = $this->seo->sitemapEntries();

        foreach ($this->providers() as $key => $provider) {
            try {
                foreach ($provider->urls() as $entry) {
                    $entry = $entry instanceof SitemapEntry ? $entry : (is_array($entry) ? SitemapEntry::fromArray($entry, $key) : null);

                    if ($entry !== null) {
                        $entries->push($entry);
                    }
                }
            } catch (Throwable $exception) {
                $this->failures[$key] = $exception->getMessage();
                report($exception);
            }
        }

        return $entries->unique(static fn (SitemapEntry $entry): string => $entry->loc)->values();
    }

    /**
     * How many `/sitemap-{n}.xml` files the URL set needs (1 when it fits in one `<urlset>`).
     *
     * @param  Collection<int, SitemapEntry>|null  $urls
     */
    public function chunkCount(?Collection $urls = null): int
    {
        $count = ($urls ?? $this->urls())->count();

        return max(1, (int) ceil($count / self::MAX_URLS_PER_FILE));
    }

    /**
     * The XML. `$chunk` null is `/sitemap.xml`; `$chunk` n is `/sitemap-{n}.xml`, which exists only when
     * the set is split. Null means "404".
     *
     * @param  Collection<int, SitemapEntry>|null  $urls
     */
    public function generate(?int $chunk = null, ?Collection $urls = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $urls ??= $this->urls();
        $chunks = $this->chunkCount($urls);

        if ($chunks === 1) {
            return $chunk === null ? $this->urlset($urls) : null;
        }

        if ($chunk === null) {
            return $this->index($chunks);
        }

        if ($chunk < 1 || $chunk > $chunks) {
            return null;
        }

        return $this->urlset($urls->slice(($chunk - 1) * self::MAX_URLS_PER_FILE, self::MAX_URLS_PER_FILE)->values());
    }

    /**
     * `generate()` behind the version-stamped cache: any publish makes the next request rebuild it.
     */
    public function cached(?int $chunk = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = $this->cacheKey($chunk);

        try {
            $xml = $this->cache->get($key);

            if (is_string($xml)) {
                return $xml;
            }

            // A chunk number that cannot exist is answered from the cached file count, never by building
            // the whole URL set again: `/sitemap-2.xml` … `/sitemap-999999.xml` are anonymous, uncached
            // 404s, and each would otherwise re-query every page and provider.
            if ($chunk !== null) {
                $chunks = $this->cachedChunkCount();

                if ($chunks === 1 || $chunk < 1 || $chunk > $chunks) {
                    return null;
                }
            }

            $xml = $this->generate($chunk);

            if ($xml !== null) {
                $this->cache->put($key, $xml, self::CACHE_SECONDS);
            }

            return $xml;
        } catch (Throwable $exception) {
            report($exception);

            return $this->generate($chunk);
        }
    }

    /**
     * Rebuild now, warm the cache and append a `sitemap_generations` row — `ok` or `failed`.
     */
    public function regenerate(string $trigger = 'manual', ?User $by = null): SitemapGeneration
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown sitemap trigger [%s]. Use: %s.', $trigger, implode(', ', self::TRIGGERS)));
        }

        $started = hrtime(true);
        $status = 'ok';
        $reason = null;
        $count = 0;
        $bytes = 0;
        $providers = [];

        try {
            $urls = $this->urls();
            $count = $urls->count();
            $providers = $urls->countBy(static fn (SitemapEntry $entry): string => $entry->provider)->all();

            if ($this->isEnabled()) {
                $files = [null];

                for ($chunk = 1, $chunks = $this->chunkCount($urls); $chunks > 1 && $chunk <= $chunks; $chunk++) {
                    $files[] = $chunk;
                }

                foreach ($files as $chunk) {
                    $xml = (string) $this->generate($chunk, $urls);
                    $bytes += strlen($xml);

                    $this->cache->put($this->cacheKey($chunk), $xml, self::CACHE_SECONDS);
                }

                $this->cache->put($this->chunkCountKey(), $this->chunkCount($urls), self::CACHE_SECONDS);
            }

            if ($this->failures !== []) {
                $reason = 'Skipped failing providers: '.implode('; ', array_map(
                    static fn (string $key, string $message): string => $key.' ('.$message.')',
                    array_keys($this->failures),
                    $this->failures
                ));
            }
        } catch (Throwable $exception) {
            report($exception);
            $status = 'failed';
            $reason = $exception->getMessage();
        }

        $attributes = [
            'url_count' => $count,
            'byte_size' => min($bytes, 4_294_967_295),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'trigger' => $trigger,
            'status' => $status,
            'failure_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'providers' => json_encode((object) $providers, JSON_UNESCAPED_SLASHES),
            'created_by' => $by?->getKey() ?? $this->auditor->actorId(),
            'created_at' => Carbon::now(),
        ];

        $id = $this->db->connection()->table('sitemap_generations')->insertGetId($attributes);

        /** @var SitemapGeneration */
        return SitemapGeneration::query()->hydrate([['id' => $id] + $attributes])->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The version-stamped key of one sitemap file. The index is keyed `index`, never `0`, so a request
     * for `/sitemap-0.xml` can never be answered from the index's entry (integration K-6).
     */
    private function cacheKey(?int $chunk): string
    {
        return $this->version->key('sitemap', [$chunk ?? 'index']);
    }

    private function chunkCountKey(): string
    {
        return $this->version->key('sitemap', ['chunks']);
    }

    /**
     * How many chunk files the current URL set has, built at most once per cache version.
     */
    private function cachedChunkCount(): int
    {
        $key = $this->chunkCountKey();
        $stored = $this->cache->get($key);

        if (is_int($stored) || (is_string($stored) && ctype_digit($stored))) {
            return max(1, (int) $stored);
        }

        $count = $this->chunkCount();
        $this->cache->put($key, $count, self::CACHE_SECONDS);

        return $count;
    }

    /**
     * @return array<string, object>
     */
    private function providers(): array
    {
        $providers = [];
        $registry = self::REGISTRY;

        if (class_exists($registry) && method_exists($registry, 'providers')) {
            try {
                foreach ((array) $registry::providers() as $key => $provider) {
                    if (is_object($provider) && method_exists($provider, 'urls')) {
                        $providers[$this->providerKey($key, $provider)] = $provider;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        foreach (self::$extensions as $key => $provider) {
            $providers[$this->providerKey($key, $provider)] = $provider;
        }

        // Phase 3's own entries come from SeoService under these keys; a provider may not shadow them.
        unset($providers['pages'], $providers['static']);

        return $providers;
    }

    private function providerKey(int|string $key, object $provider): string
    {
        if (method_exists($provider, 'key')) {
            $declared = trim((string) $provider->key());

            if ($declared !== '') {
                return $declared;
            }
        }

        return is_string($key) && $key !== '' ? $key : class_basename($provider);
    }

    /**
     * @param  Collection<int, SitemapEntry>  $urls
     */
    private function urlset(Collection $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="'.self::NAMESPACE_URI.'">',
        ];

        foreach ($urls as $entry) {
            $url = '<url><loc>'.$this->escape($entry->loc).'</loc>';

            if ($entry->lastmod !== null) {
                $url .= '<lastmod>'.$this->escape($entry->lastmod->toAtomString()).'</lastmod>';
            }

            $url .= '<changefreq>'.$entry->changefreq->value.'</changefreq>';
            $url .= '<priority>'.$entry->priority.'</priority></url>';

            $lines[] = $url;
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }

    private function index(int $chunks): string
    {
        $base = $this->seo->baseUrl();
        $now = Carbon::now()->toAtomString();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="'.self::NAMESPACE_URI.'">',
        ];

        for ($chunk = 1; $chunk <= $chunks; $chunk++) {
            $lines[] = '<sitemap><loc>'.$this->escape($base.'/sitemap-'.$chunk.'.xml').'</loc><lastmod>'.$now.'</lastmod></sitemap>';
        }

        $lines[] = '</sitemapindex>';

        return implode("\n", $lines);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->settings->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
