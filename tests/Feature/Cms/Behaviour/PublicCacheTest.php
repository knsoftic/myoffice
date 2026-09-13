<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\CacheVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.3 — caching and invalidation (FT-24, FT-25, FT-26, FT-27).
 *
 * D22's second half: every public response is stored under a version-stamped key, any publish
 * increments the stamp by exactly one (INV-8), and the stamp is the only invalidation there is — which
 * works on the database cache store, which has no tags. The bypass list of §6.7 is what keeps one
 * visitor's page from being served to another (R-4): the `?ref=` referral parameter in particular is
 * keyed, never shared.
 *
 * The array store under test is inspected key by key, so "stored", "served from the cache" and "never
 * served again" are observed directly rather than inferred from timing.
 */
final class PublicCacheTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Tables whose reads belong to the framework shell rather than to composing the page: the session
     * handler, the settings payload and the module map (each memoised or cached for the whole process in
     * production), and the cache store itself.
     */
    private const INFRASTRUCTURE_TABLES = ['sessions', 'settings', 'modules', 'cache', 'cache_locks'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /** FT-24 */
    public function test_second_anonymous_request_is_served_from_cache(): void
    {
        $this->assertSame([], $this->pageCacheKeys(), 'The page cache starts cold.');

        $first = $this->get('/')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag, 'A cacheable public page carries an ETag.');
        $this->assertCount(1, $this->pageCacheKeys(), 'The first anonymous request stores the page.');

        $sectionQueries = [];

        DB::listen(static function (QueryExecuted $query) use (&$sectionQueries): void {
            if (str_contains($query->sql, 'website_sections')) {
                $sectionQueries[] = $query->sql;
            }
        });

        $second = $this->get('/')->assertOk();

        $this->assertSame([], $sectionQueries, 'The second request must issue zero queries against website_sections.');
        $this->assertSame($first->getContent(), $second->getContent(), 'The cached response is byte-identical.');
        $this->assertSame($etag, $second->headers->get('ETag'), 'The cached response carries the same ETag.');
        $this->assertCount(1, $this->pageCacheKeys());
    }

    /** FT-25 */
    public function test_publishing_invalidates_every_public_page_at_once(): void
    {
        $this->publishHeroHeading('FT25 Before Publish');
        $this->makePage('ft25-other-page', 'FT25 Other Page', '<p>FT25 other body</p>');

        $home = $this->get('/')->assertOk()->assertSee('FT25 Before Publish');
        $this->get('/ft25-other-page')->assertOk()->assertSee('FT25 other body');

        $stored = $this->pageCacheKeys();
        $this->assertCount(2, $stored);

        $version = $this->cacheVersion()->version();

        $hero = $this->sections()->saveDraft($this->seededSection('hero'), ['heading' => 'FT25 After Publish']);
        $this->assertSame($version, $this->cacheVersion()->version(), 'A draft save never bumps the cache.');

        $this->publisher()->publish($hero);

        $this->assertSame($version + 1, $this->cacheVersion()->version(), 'One publish increments the stamp by exactly one.');
        $this->assertSame($version + 1, (int) Cache::get(CacheVersion::KEY), 'The stored stamp moved, not only the memo.');

        foreach ($stored as $key) {
            $this->assertStringStartsWith(CacheVersion::PREFIX.':v'.$version.':', $key, 'Every stored page belongs to the old version.');
        }

        $fresh = $this->get('/')->assertOk()->assertSee('FT25 After Publish')->assertDontSee('FT25 Before Publish');

        $this->assertCount(3, $this->pageCacheKeys(), 'The next request is a miss and stores under the new version.');
        $this->assertNotSame($home->headers->get('ETag'), $fresh->headers->get('ETag'));

        // Every page moved at once — the other page is a miss too, with no per-page invalidation.
        $this->get('/ft25-other-page')->assertOk();
        $this->assertCount(4, $this->pageCacheKeys());

        // The old entry is never served again.
        $this->get('/')->assertOk()->assertSee('FT25 After Publish')->assertDontSee('FT25 Before Publish');
        $this->assertCount(4, $this->pageCacheKeys());
    }

    /** FT-26 */
    public function test_cache_is_bypassed_where_it_must_be(): void
    {
        // An authenticated request.
        $this->actingAs($this->createUserWithRole('Student'))->get('/')->assertOk();
        $this->assertSame([], $this->pageCacheKeys(), 'An authenticated render is never stored.');

        $this->becomeGuest();

        // A preview-shaped request.
        $this->get('/?preview=1')->assertOk();
        $this->assertSame([], $this->pageCacheKeys(), 'A preview is never stored.');

        // The master switch off.
        $this->setSetting('website.cache_enabled', false);
        $this->get('/')->assertOk();
        $this->get('/')->assertOk();
        $this->assertSame([], $this->pageCacheKeys(), 'website.cache_enabled = false stores nothing.');
        $this->setSetting('website.cache_enabled', true);

        // A non-200 response.
        $this->get('/ft26-no-such-page')->assertNotFound();
        $this->assertSame([], $this->pageCacheKeys(), 'A 404 is never stored.');

        // A request carrying a flash message.
        $this->app['session']->flash('toast', ['type' => 'success', 'message' => 'FT-26 flashed']);
        $this->get('/')->assertOk();
        $this->assertSame([], $this->pageCacheKeys(), 'A request with a flash message is never stored.');
        $this->flushSession();

        // A query key outside the whitelist.
        $this->get('/?utm_source=ft26')->assertOk();
        $this->assertSame([], $this->pageCacheKeys(), 'A query key outside page/category/ref bypasses the cache.');

        // `ref` is whitelisted AND keyed.
        $withRef = $this->get('/?ref=COL-1024')->assertOk();
        $this->assertCount(1, $this->pageCacheKeys(), 'A referral page is cached under its own key.');

        $plain = $this->get('/')->assertOk();
        $this->assertCount(2, $this->pageCacheKeys(), 'A visitor without the parameter is a miss, not served the referral copy.');
        $this->assertNotSame($withRef->headers->get('ETag'), $plain->headers->get('ETag'));

        $this->assertSame($withRef->headers->get('ETag'), $this->get('/?ref=COL-1024')->assertOk()->headers->get('ETag'));
        $this->assertSame($plain->headers->get('ETag'), $this->get('/')->assertOk()->headers->get('ETag'));
        $this->assertCount(2, $this->pageCacheKeys());
    }

    /** FT-27 */
    public function test_home_page_query_count_is_bounded(): void
    {
        foreach (['header' => SectionPlacement::GlobalHeader, 'hero' => SectionPlacement::Home, 'about' => SectionPlacement::Home,
            'faq' => SectionPlacement::Home, 'cta' => SectionPlacement::Home, 'footer' => SectionPlacement::GlobalFooter] as $key => $placement) {
            $this->assertNotNull($this->snapshotOf($this->seededSection($key, $placement)), sprintf('The seeded %s section is published.', $key));
        }

        $baseline = $this->coldHomeQueries();

        $this->assertLessThanOrEqual(8, count($baseline), "A cold anonymous home page issued more than 8 queries:\n".implode("\n", $baseline));

        // Grow every collection the page renders — items, media, menu entries, sections — then render cold again.
        $hero = $this->seededSection('hero');

        foreach (WebsiteSectionItem::query()->where('website_section_id', $hero->getKey())->get() as $item) {
            $this->sections()->deleteItem($item);
        }

        foreach (range(1, 8) as $n) {
            $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT27 Stat '.$n, 'value_mode' => 'manual', 'manual_value' => (string) ($n * 10)]);
        }

        $this->publisher()->publish($this->sections()->saveDraft($hero, [], ['hero_image' => (int) $this->makeImageAsset()->getKey()]));

        $header = $this->seededSection('header', SectionPlacement::GlobalHeader);
        $menu = $this->menuAt(MenuLocation::Header);

        foreach (range(1, 6) as $n) {
            $this->menus()->storeItem($menu, ['label' => 'FT27 Link '.$n, 'link_type' => MenuItemLinkType::Url->value, 'url' => '/ft27-'.$n]);
        }

        $this->publisher()->publish($header);

        foreach (range(1, 3) as $n) {
            $section = $this->sections()->place('rich_content', SectionPlacement::Home);
            $section = $this->sections()->saveDraft($section, ['heading' => 'FT27 Block '.$n], ['image_1' => (int) $this->makeImageAsset()->getKey()]);

            foreach (range(1, 4) as $h) {
                $this->sections()->upsertItem($section, 'highlight', ['title' => sprintf('FT27 Highlight %d.%d', $n, $h)]);
            }

            $this->publisher()->publish($section);
        }

        $grown = $this->coldHomeQueries();

        $this->assertLessThanOrEqual(
            count($baseline),
            count($grown),
            "More items, media and menu entries made the cold home page issue more queries (an N+1):\nbefore:\n".implode("\n", $baseline)."\nafter:\n".implode("\n", $grown),
        );
    }

    /**
     * The page-composition queries of one cold anonymous home page render.
     *
     * @return list<string>
     */
    private function coldHomeQueries(): array
    {
        $this->becomeGuest();
        $this->bumpPublicCache('FT-27 cold render');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/')->assertOk();

        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        $queries = [];

        foreach ($log as $entry) {
            $sql = (string) ($entry['query'] ?? '');

            if (preg_match('~(?:from|into|update)\s+`?('.implode('|', self::INFRASTRUCTURE_TABLES).')`?[\s;]~i', $sql.' ') === 1) {
                continue;
            }

            $queries[] = $sql;
        }

        return $queries;
    }
}
