<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\SectionPlacement;
use App\Http\Middleware\CachePublicResponse;
use App\Models\Module;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\SeoService;
use App\Services\Core\ModuleService;
use App\Support\Cms\PublicOrigin;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Cms\Behaviour\Fixtures\ModuleGatedSectionProvider;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 2 regressions — what the shared public caches may be built from, keyed by and grow to
 * (phase-03 §6.5, §6.7, INV-8, INV-12, D22, D26).
 *
 *   · The `Host` header is chosen by the client. The sitemap is cached for every visitor under a key with
 *     no host in it, so its absolute URLs come from `seo.canonical_base_url` or `config('app.url')`, never
 *     from the request: one anonymous request with `Host: evil.test` cannot rewrite every later crawler's
 *     sitemap.
 *   · The page cache is bounded: a foreign host renders but is never stored; `page` / `category` are keyed
 *     only on a route that declares them; the `?ref=` variants stored per cache version are capped; the
 *     expired rows the database store keeps forever are pruned by `cms:cache-prune`.
 *   · A module switch changes what a public page may show, so it bumps the public cache like a publish.
 */
final class PublicCacheBoundsTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const FOREIGN_ORIGIN = 'http://evil.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();

        $this->assertNull(PublicOrigin::canonicalBaseUrl(), 'These scenarios start with no canonical base URL, the seeded default.');
    }

    protected function tearDown(): void
    {
        $this->forgetTestSectionTypes();

        parent::tearDown();
    }

    public function test_a_spoofed_host_never_reaches_the_cached_sitemap_or_robots_txt(): void
    {
        $origin = PublicOrigin::applicationUrl();

        $this->assertStringNotContainsString('evil.test', $origin, 'The fixture needs an application URL that is not the attacker host.');

        // The attacker's request is the one that builds the sitemap for this cache version.
        $poisoned = $this->get(self::FOREIGN_ORIGIN.'/sitemap.xml')->assertOk();

        $this->assertStringNotContainsString('evil.test', (string) $poisoned->getContent(), 'A sitemap is never built from the request Host.');
        $this->assertStringContainsString('<loc>'.$origin.'/</loc>', (string) $poisoned->getContent(), 'Without a canonical base URL the sitemap uses config(app.url).');

        // Every later visitor on the real host is served the entry that request stored.
        $served = $this->get($this->onSiteHost('/sitemap.xml'))->assertOk();

        $this->assertStringNotContainsString('evil.test', (string) $served->getContent());
        $this->assertSame($poisoned->getContent(), $served->getContent(), 'The sitemap is host-independent: the scheduler, a crawler and an attacker all build the same file.');

        $robots = (string) $this->get(self::FOREIGN_ORIGIN.'/robots.txt')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil.test', $robots);
        $this->assertStringContainsString('Sitemap: '.$origin.'/sitemap.xml', $robots);

        // A configured canonical base still wins over config(app.url).
        $this->setSetting('seo.canonical_base_url', 'https://www.ft-canonical.example/');
        $this->bumpPublicCache('canonical base URL set');

        $this->assertSame('https://www.ft-canonical.example', app(SeoService::class)->baseUrl());
        $this->get(self::FOREIGN_ORIGIN.'/sitemap.xml')->assertOk()->assertSee('<loc>https://www.ft-canonical.example/</loc>', false)->assertDontSee('evil.test');
    }

    /**
     * The second layer: outside `local` and the test runner, TrustHosts answers a foreign Host with a 400
     * before routing. It is on the global stack, and it trusts the canonical base URL's host besides the
     * application URL and its subdomains (which the middleware adds itself).
     */
    public function test_trusted_hosts_are_enforced_on_the_global_stack(): void
    {
        $kernel = app(HttpKernel::class);

        $this->assertInstanceOf(FoundationHttpKernel::class, $kernel);
        $this->assertContains(TrustHosts::class, $kernel->getGlobalMiddleware(), 'bootstrap/app.php enables trustHosts().');

        $this->assertSame([], PublicOrigin::trustedHostPatterns(), 'With no canonical base URL only the application URL (added by TrustHosts) is trusted.');

        $this->setSetting('seo.canonical_base_url', 'https://www.ft-canonical.example/');

        $patterns = PublicOrigin::trustedHostPatterns();
        $trusted = (new class(app()) extends TrustHosts
        {
            /** @return array<int, string|null> */
            public function trusted(): array
            {
                return $this->hosts();
            }
        })->trusted();

        $this->assertSame(['^www\.ft\-canonical\.example$'], $patterns);
        $this->assertSame(1, preg_match('{'.$patterns[0].'}i', 'www.ft-canonical.example'));
        $this->assertSame(0, preg_match('{'.$patterns[0].'}i', 'evil.test'));
        $this->assertContains($patterns[0], $trusted, 'TrustHosts reads the canonical host through PublicOrigin at request time.');
        $this->assertCount(2, array_filter($trusted), 'The canonical host and the application URL with its subdomains — nothing else.');
    }

    public function test_a_foreign_host_renders_but_never_reads_or_writes_the_page_cache(): void
    {
        $this->assertTrue(PublicOrigin::servesHost(Request::create(PublicOrigin::applicationUrl().'/')), 'The application URL is served from the cache.');
        $this->assertFalse(PublicOrigin::servesHost(Request::create(self::FOREIGN_ORIGIN.'/')));

        $this->get(self::FOREIGN_ORIGIN.'/')->assertOk();
        $this->get('http://EVIL.test:8443/')->assertOk();

        $this->assertSame([], $this->pageCacheKeys(), 'A client-chosen Host mints no stored page.');

        $this->get($this->onSiteHost('/'))->assertOk();
        $this->assertCount(1, $this->pageCacheKeys(), 'The application host is cached as before.');

        // Nor is the stored copy served to a foreign host: it renders again, and still stores nothing.
        $this->get(self::FOREIGN_ORIGIN.'/')->assertOk()->assertHeaderMissing('ETag');
        $this->assertCount(1, $this->pageCacheKeys());

        // The canonical base URL's host is the site's own too.
        $this->setSetting('seo.canonical_base_url', 'https://www.ft-canonical.example');

        $this->get('https://www.ft-canonical.example/')->assertOk();
        $this->assertCount(2, $this->pageCacheKeys(), 'The canonical host is served from the cache.');
    }

    /**
     * An absolute URL on the application's own host. The test client builds a relative URL from the last
     * request's root, so after a request to a foreign host a bare '/' would go there again.
     */
    private function onSiteHost(string $path): string
    {
        return PublicOrigin::applicationUrl().$path;
    }

    public function test_page_and_category_are_keyed_only_where_the_route_declares_them(): void
    {
        // Phase 3's own pages read neither parameter: a value can never change them, so it is never keyed.
        $this->get('/?page=2')->assertOk();
        $this->get('/?category=news')->assertOk();
        $this->assertSame([], $this->pageCacheKeys(), 'An undeclared page / category bypasses the cache.');

        Route::middleware(['web', 'site', 'site.cache:page'])
            ->get('ft-cache-bounds/list', static fn () => response('<!doctype html><title>FT list</title><p>FT list page '.e((string) request('page', '1')).'</p>'));

        $this->get('/ft-cache-bounds/list?page=2')->assertOk()->assertSee('FT list page 2');
        $this->get('/ft-cache-bounds/list?page=3')->assertOk()->assertSee('FT list page 3');
        $this->assertCount(2, $this->pageCacheKeys(), 'A declared page is keyed per value.');

        $this->get('/ft-cache-bounds/list?page=2')->assertOk()->assertSee('FT list page 2');
        $this->assertCount(2, $this->pageCacheKeys(), 'A declared value is served from its own copy.');

        foreach (['0', '01', '10000', '2x'] as $unsafe) {
            $this->get('/ft-cache-bounds/list?page='.$unsafe)->assertOk();
        }

        $this->get('/ft-cache-bounds/list?category=news')->assertOk();
        $this->assertCount(2, $this->pageCacheKeys(), 'An out-of-pattern page, or a category this route does not declare, stores nothing.');

        // `ref` stays keyed everywhere (FT-26), but only in the referral-code shape of phase-08-09.
        $this->get('/?ref='.str_repeat('A', 33))->assertOk();
        $this->get('/?ref=A_B-1')->assertOk();
        $this->assertCount(2, $this->pageCacheKeys(), 'A value that cannot be a referral code is never keyed.');

        $this->get('/?ref=COL-1024')->assertOk();
        $this->assertCount(3, $this->pageCacheKeys());
    }

    public function test_query_string_variants_are_capped_per_cache_version(): void
    {
        $counter = app(CacheVersion::class)->key(CachePublicResponse::VARIANT_COUNTER_NAMESPACE, ['count']);

        $this->get('/?ref=COL-1001')->assertOk();
        $this->assertCount(1, $this->pageCacheKeys());
        $this->assertSame(1, (int) Cache::get($counter), 'A stored variant is counted.');

        $this->get('/?ref=COL-1001')->assertOk();
        $this->assertSame(1, (int) Cache::get($counter), 'Serving a stored variant counts nothing.');

        // The cap is reached: a new variant still renders, correctly, but is not stored.
        Cache::put($counter, CachePublicResponse::MAX_QUERY_VARIANTS, 3600);

        $this->get('/?ref=COL-1002')->assertOk();
        $this->assertCount(1, $this->pageCacheKeys(), 'Past the cap a new ?ref= variant is not stored.');

        // A plain path is bounded by the pages that exist, and is never capped.
        $this->get('/')->assertOk();
        $this->assertCount(2, $this->pageCacheKeys());

        // The count belongs to the version: the next publish starts it again.
        $this->bumpPublicCache('new version');

        $this->get('/?ref=COL-1002')->assertOk();
        $this->assertCount(3, $this->pageCacheKeys(), 'A new cache version stores variants again.');
    }

    public function test_cache_prune_removes_only_expired_rows_of_the_database_store(): void
    {
        $now = Carbon::now()->getTimestamp();

        DB::table('cache')->insert([
            ['key' => 'ft-prune-expired-1', 'value' => 's:1:"a";', 'expiration' => $now - 10],
            ['key' => 'ft-prune-expired-2', 'value' => 's:1:"b";', 'expiration' => $now - 86_400],
            ['key' => 'ft-prune-expired-3', 'value' => 's:1:"c";', 'expiration' => $now],
            ['key' => 'ft-prune-live', 'value' => 's:1:"d";', 'expiration' => $now + 3600],
            ['key' => 'ft-prune-forever', 'value' => 's:1:"e";', 'expiration' => $now + 315_360_000],
        ]);

        // Under a store that expires its own entries there is nothing to do, and nothing is touched.
        $this->artisan('cms:cache-prune')->assertSuccessful();
        $this->assertSame(5, DB::table('cache')->where('key', 'like', 'ft-prune-%')->count());

        config(['cache.default' => 'database']);

        $this->artisan('cms:cache-prune', ['--chunk' => 1])
            ->expectsOutputToContain('3 expired cache row(s) pruned.')
            ->assertSuccessful();

        $this->assertSame(
            ['ft-prune-forever', 'ft-prune-live'],
            DB::table('cache')->where('key', 'like', 'ft-prune-%')->orderBy('key')->pluck('key')->all(),
            'Only expired rows go; a live entry and a forever entry stay.',
        );
    }

    public function test_a_module_switch_invalidates_every_stored_public_page(): void
    {
        $this->registerTestSectionType('ft_module_strip', [
            'label' => 'Review module strip',
            'group' => 'content',
            'placements' => [SectionPlacement::Home->value => 920],
            'unique' => false,
            'required' => false,
            'is_live' => true,
            'provider' => ModuleGatedSectionProvider::class,
            'view' => 'site.sections.services',
            'fields' => [
                'heading' => ['label' => 'Heading', 'type' => 'text', 'default' => null],
            ],
            'repeaters' => [],
            'media' => [],
        ]);

        $section = $this->sections()->place('ft_module_strip', SectionPlacement::Home);
        $this->publisher()->publish($this->sections()->saveDraft($section, ['heading' => 'FT Module Strip Heading']));

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee(ModuleGatedSectionProvider::CARD);
        $this->assertCount(1, $this->pageCacheKeys());

        $module = Module::query()->where('slug', ModuleGatedSectionProvider::MODULE)->firstOrFail();
        $version = (int) Cache::get(CacheVersion::KEY, 1);

        app(ModuleService::class)->setEnabled($module, false, 'Review round 2: the public cache follows a module switch');

        $this->assertSame($version + 1, (int) Cache::get(CacheVersion::KEY), 'One module switch bumps the public cache version by exactly one.');

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT Module Strip Heading')->assertDontSee(ModuleGatedSectionProvider::CARD);

        // Switching it back is the same act.
        app(ModuleService::class)->setEnabled($module->fresh(), true);

        $this->assertSame($version + 2, (int) Cache::get(CacheVersion::KEY));

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee(ModuleGatedSectionProvider::CARD);

        // A switch to the state the module is already in moves nothing and bumps nothing.
        app(ModuleService::class)->setEnabled($module->fresh(), true);
        $this->assertSame($version + 2, (int) Cache::get(CacheVersion::KEY));
    }
}
