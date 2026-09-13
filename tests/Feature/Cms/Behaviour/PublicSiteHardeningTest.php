<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Contracts\Cms\SitemapUrlProvider;
use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Enums\UserStatus;
use App\Services\Cms\PublicCache;
use App\Services\Core\SettingsService;
use App\Support\SitemapRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 1 regressions for the public site's edges: cache invalidation by settings (§6.7, INV-8),
 * SEO route targets and the sitemap (§6.5), draft preview and the maintenance bypass (§6.10, §6.12, §7.6,
 * §9), the sitemap chunk route, media re-uploads (§6.8), the menu link check, snapshot verification
 * (§10.4) and the extension classes later phases call (`SitemapRegistry`, `PublicCache`).
 */
final class PublicSiteHardeningTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    protected function tearDown(): void
    {
        SitemapRegistry::flush();

        parent::tearDown();
    }

    public function test_saving_a_setting_the_public_site_shows_invalidates_the_page_cache(): void
    {
        $this->becomeGuest();
        $this->get('/')->assertOk();
        $this->assertCount(1, $this->pageCacheKeys(), 'The home page is cached.');

        $version = $this->cacheVersion()->refresh();

        app(SettingsService::class)->asSystem()->update('seo', ['robots_indexable' => false]);

        $this->assertSame($version + 1, $this->cacheVersion()->refresh(), 'One save, one bump.');

        $response = $this->get('/')->assertOk();
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'), 'The cached index, follow page is not served after indexing was turned off.');

        // A setting no public page reads leaves the site cache alone.
        $version = $this->cacheVersion()->refresh();

        app(SettingsService::class)->asSystem()->update('security', ['login_max_attempts' => 6]);

        $this->assertSame($version, $this->cacheVersion()->refresh());
    }

    public function test_seo_route_targets_are_limited_to_the_public_sites_own_pages(): void
    {
        $admin = $this->createSuperAdmin();

        foreach (['route:admin.users.index', 'route:admin.dashboard', 'route:login', 'route:site.preview.page', 'route:site.page', 'route:site.sitemap'] as $target) {
            $this->actingAs($admin)
                ->putJson(route('admin.website.seo.update'), ['target' => $target, 'seo' => ['title' => 'FT leaked route']])
                ->assertStatus(422)
                ->assertJsonValidationErrors('target');
        }

        $this->assertFalse(DB::table('seo_meta')->where('title', 'FT leaked route')->exists(), 'No SEO row was written for a non-public route.');

        $this->actingAs($admin)
            ->putJson(route('admin.website.seo.update'), ['target' => 'route:site.home', 'seo' => ['title' => 'FT home SEO title']])
            ->assertOk();

        // A row stored by code (or before this rule) never puts an admin URL in the anonymous sitemap.
        $now = Carbon::now();

        DB::table('seo_meta')->insert([
            'route_key' => 'admin.dashboard',
            'robots' => 'index_follow',
            'og_type' => 'website',
            'sitemap_include' => true,
            'sitemap_priority' => '0.5',
            'sitemap_changefreq' => 'weekly',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->bumpPublicCache('FT admin route row');
        $this->becomeGuest();

        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>', $xml);
        $this->assertStringNotContainsString('/admin', $xml);
    }

    public function test_preview_and_the_maintenance_bypass_require_an_account_in_good_standing(): void
    {
        $page = $this->makePage('ft-standing-draft', 'FT Standing Draft', '<p>FT standing draft body.</p>', publish: false);
        $hero = $this->publishHeroHeading('FT Standing Live Hero');
        $this->sections()->saveDraft($hero, ['heading' => 'FT Standing Draft Hero']);

        $permissions = ['pages.view', 'website_sections.view'];
        $good = $this->createUserWithPermissions($permissions);
        $mustChange = $this->createUserWithPermissions($permissions, attributes: ['must_change_password' => true]);
        $suspended = $this->createUserWithPermissions($permissions, attributes: ['status' => UserStatus::Suspended->value]);

        $this->actingAs($good)->get(route('site.preview.page', $page))->assertOk()->assertSee('FT standing draft body.');
        $this->actingAs($good)->get('/?preview=1')->assertOk()->assertSee('FT Standing Draft Hero');

        foreach (['a pending password change' => $mustChange, 'a suspended account' => $suspended] as $case => $user) {
            $this->becomeGuest();

            $this->actingAs($user)->get(route('site.preview.page', $page))->assertNotFound();
            $this->actingAs($user)->get(route('site.preview.section', $hero))->assertNotFound();

            $html = (string) $this->actingAs($user)->get('/?preview=1')->assertOk()->getContent();
            $this->assertStringContainsString('FT Standing Live Hero', $html, $case.' gets the live page.');
            $this->assertStringNotContainsString('FT Standing Draft Hero', $html, $case.' never sees a draft.');
        }

        $this->setSetting('maintenance.maintenance_mode', true);

        $this->becomeGuest();
        $this->actingAs($good)->get('/')->assertOk();

        foreach ([$mustChange, $suspended] as $user) {
            $this->becomeGuest();
            $this->actingAs($user)->get('/')->assertStatus(503);
        }
    }

    public function test_preview_routes_do_not_reveal_whether_an_id_exists(): void
    {
        $draft = $this->makePage('ft-probe-draft', 'FT Probe Draft', null, publish: false);
        $section = $this->placeRichContent('FT Probe Section', publish: false);

        $cases = [
            ['site.preview.page', 'page', (int) $draft->getKey(), (int) DB::table('pages')->max('id') + 1000],
            ['site.preview.section', 'section', (int) $section->getKey(), (int) DB::table('website_sections')->max('id') + 1000],
        ];

        $this->becomeGuest();

        foreach ($cases as [$route, $parameter, $existing, $absent]) {
            $this->get(route($route, [$parameter => $existing, 'signature' => 'forged']))->assertForbidden();
            $this->get(route($route, [$parameter => $absent, 'signature' => 'forged']))->assertForbidden();

            $this->get(route($route, [$parameter => $existing]))->assertNotFound();
            $this->get(route($route, [$parameter => $absent]))->assertNotFound();
        }

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->get(route('site.preview.page', ['page' => $cases[0][3]]))->assertNotFound();
        $this->actingAs($admin)->get(route('site.preview.page', ['page' => $cases[0][2]]))->assertOk();
    }

    public function test_a_sitemap_chunk_that_cannot_exist_does_not_rebuild_the_url_set(): void
    {
        $this->becomeGuest();
        $this->get('/sitemap-2.xml')->assertNotFound();

        $pageReads = 0;

        DB::listen(static function (QueryExecuted $query) use (&$pageReads): void {
            if (preg_match('~\bfrom\s+`?pages`?~i', $query->sql) === 1) {
                $pageReads++;
            }
        });

        foreach ([3, 17, 999999] as $chunk) {
            $this->get('/sitemap-'.$chunk.'.xml')->assertNotFound();
        }

        $this->assertSame(0, $pageReads, 'A chunk that cannot exist is answered from the cached file count.');

        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_uploading_the_bytes_of_a_trashed_asset_needs_the_restore_permission(): void
    {
        Storage::fake('public');

        $bytes = $this->jpegBytes(640, 480, 200, 40, 40);
        $asset = $this->media()->store(UploadedFile::fake()->createWithContent('ft-trashed.jpg', $bytes), MediaCollection::General);
        $this->media()->delete($asset, 'FT removed on purpose');
        $this->assertSoftDeleted('media_assets', ['id' => $asset->getKey()]);

        $uploader = $this->createUserWithPermissions(['website_media.view_any', 'website_media.view', 'website_media.upload']);

        $this->actingAs($uploader)
            ->post(route('admin.website.media.store'), ['file' => UploadedFile::fake()->createWithContent('again.jpg', $bytes), 'alt_text' => 'FT uploader alt'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSoftDeleted('media_assets', ['id' => $asset->getKey()]);

        // Not trashed: the upload reuses the row but cannot describe someone else's asset without edit.
        DB::table('media_assets')->where('id', $asset->getKey())->update(['deleted_at' => null]);

        $this->actingAs($uploader)
            ->post(route('admin.website.media.store'), ['file' => UploadedFile::fake()->createWithContent('again.jpg', $bytes), 'alt_text' => 'FT uploader alt'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNull(DB::table('media_assets')->where('id', $asset->getKey())->value('alt_text'));

        // With `website_media.edit`, the same upload fills the empty alt text.
        $editor = $this->createUserWithPermissions(['website_media.view_any', 'website_media.view', 'website_media.upload', 'website_media.edit']);

        $this->actingAs($editor)
            ->post(route('admin.website.media.store'), ['file' => UploadedFile::fake()->createWithContent('again.jpg', $bytes), 'alt_text' => 'FT editor alt'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('FT editor alt', DB::table('media_assets')->where('id', $asset->getKey())->value('alt_text'));

        // Trashed again: still refused to an editor, and brought back for someone who may restore media
        // (the registry declares no `website_media.restore`, so that is a Super Admin — MediaPolicy::restore).
        DB::table('media_assets')->where('id', $asset->getKey())->update(['deleted_at' => Carbon::now()]);

        $this->actingAs($editor)
            ->post(route('admin.website.media.store'), ['file' => UploadedFile::fake()->createWithContent('again.jpg', $bytes)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSoftDeleted('media_assets', ['id' => $asset->getKey()]);

        $this->actingAs($this->createSuperAdmin())
            ->post(route('admin.website.media.store'), ['file' => UploadedFile::fake()->createWithContent('again.jpg', $bytes)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNotSoftDeleted('media_assets', ['id' => $asset->getKey()]);
    }

    public function test_the_menu_link_check_reads_section_anchors_once(): void
    {
        $admin = $this->createSuperAdmin();
        $menu = $this->menuAt(MenuLocation::Header);

        $target = $this->placeRichContent('FT Anchor Target');
        DB::table('website_sections')->where('id', $target->getKey())->update(['anchor' => 'ft-anchor-real']);

        foreach (range(1, 6) as $n) {
            $this->menus()->storeItem($menu, [
                'label' => 'FT Anchor '.$n,
                'link_type' => MenuItemLinkType::SectionAnchor->value,
                'anchor' => $n === 1 ? 'ft-anchor-real' : 'ft-anchor-missing-'.$n,
            ]);
        }

        $anchorReads = 0;

        DB::listen(static function (QueryExecuted $query) use (&$anchorReads): void {
            if (str_contains($query->sql, 'website_sections') && str_contains($query->sql, 'anchor')) {
                $anchorReads++;
            }
        });

        $response = $this->actingAs($admin)->getJson(route('admin.website.menus.link-check', $menu))->assertOk();

        $this->assertLessThanOrEqual(1, $anchorReads, 'Six anchor items must not issue six anchor queries.');

        $broken = collect((array) $response->json('items'))->pluck('reason', 'label');

        $this->assertArrayNotHasKey('FT Anchor 1', $broken->all(), 'An anchor a section uses is not broken.');

        foreach (range(2, 6) as $n) {
            $this->assertSame('no section uses this anchor', $broken->get('FT Anchor '.$n));
        }
    }

    public function test_snapshot_verification_fails_when_a_published_image_file_is_missing(): void
    {
        Storage::fake('public');

        $this->artisan('cms:verify-published-snapshots')->assertSuccessful();

        $asset = $this->makeImageAsset(['alt_text' => 'FT verify image']);
        $section = $this->sections()->place('rich_content', SectionPlacement::Home);
        $this->publisher()->publish($this->sections()->saveDraft($section, ['heading' => 'FT Verify Heading'], ['image_1' => (int) $asset->getKey()]));

        Log::spy();

        $this->artisan('cms:verify-published-snapshots')->assertFailed();

        Log::shouldHaveReceived('critical')->once();

        Storage::disk('public')->put($asset->path(), $this->jpegBytes(64, 64));

        $this->artisan('cms:verify-published-snapshots')->assertSuccessful();
    }

    public function test_later_phases_register_sitemap_urls_and_bump_the_cache_through_the_named_classes(): void
    {
        $provider = new class implements SitemapUrlProvider
        {
            public function key(): string
            {
                return 'ft_registry';
            }

            public function urls(): iterable
            {
                return [['loc' => 'https://ft-registry.example.test/ft-registry-url', 'changefreq' => 'weekly', 'priority' => '0.5']];
            }
        };

        SitemapRegistry::register('ft_registry', $provider);

        $this->assertThrows(fn () => SitemapRegistry::register('pages', $provider), InvalidArgumentException::class);
        $this->assertThrows(fn () => SitemapRegistry::register('static', $provider), InvalidArgumentException::class);

        $cache = app(PublicCache::class);
        $version = $cache->version();

        $this->assertSame($version + 1, $cache->bump('FT registry test'));

        $this->becomeGuest();
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://ft-registry.example.test/ft-registry-url', false);
    }
}
