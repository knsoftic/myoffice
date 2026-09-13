<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http\Concerns;

use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\User;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\CtaBlockService;
use App\Services\Cms\FaqService;
use App\Services\Cms\MediaService;
use App\Services\Cms\MenuService;
use App\Services\Cms\PageService;
use App\Services\Cms\SectionService;
use App\Support\PermissionRegistry;
use App\Support\Sidebar;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures and the route table shared by the phase-03 HTTP acceptance tests (`tests/Feature/Cms/Http`):
 * the permission matrix, module gating, Form Request validation and screen rendering.
 *
 * Everything stands on the **real seeded site** (`WebsiteCmsSeeder`: header, hero, about, faq, cta and
 * footer published; four system pages; four menus; the `primary` CTA block; three FAQ categories) and is
 * written through the real services, never a hand-built row, so a route under test sees exactly what an
 * administrator's click would have produced.
 *
 * `cmsRouteTable()` is phase-03 §7.1-§7.5 written out: every admin CMS route with its method, URI and the
 * one permission its `can:` names, plus a builder that returns the route parameters and a payload.
 * `build(false)` only reads seeded rows (a request that must be refused never reaches validation, so its
 * payload is irrelevant); `build(true)` creates a dedicated fixture and a **valid** payload, so a holder of
 * the permission gets a 200 and one route's success can never depend on another route having run first.
 *
 * The using class must also use `Tests\Feature\Concerns\InteractsWithRbac` and `RefreshDatabase`.
 * Not named `*Test.php`, so PHPUnit does not try to run it.
 */
trait CmsHttpFixtures
{
    private int $cmsFixtureCounter = 0;

    private ?MediaAsset $sharedCmsMediaAsset = null;

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    /**
     * The eight module slugs whose admin routes phase-03 §7.1-§7.5 registers (§9 "Module gating").
     *
     * @return list<string>
     */
    protected function cmsModules(): array
    {
        return ['website_sections', 'menus', 'pages', 'website_cta_blocks', 'faqs', 'faq_categories', 'seo', 'website_media'];
    }

    /**
     * Every permission of those modules, read from the registry (D4) — never spelled out here.
     *
     * @return list<string>
     */
    protected function cmsPermissions(): array
    {
        return array_values(PermissionRegistry::permissionNamesFor($this->cmsModules()));
    }

    /**
     * The index screen of each CMS module (the sidebar's target).
     *
     * @return array<string, string> module => route name
     */
    protected function cmsModuleIndexRoutes(): array
    {
        return [
            'website_sections' => 'admin.website.index',
            'menus' => 'admin.website.menus.index',
            'pages' => 'admin.website.pages.index',
            'website_cta_blocks' => 'admin.website.cta-blocks.index',
            'faqs' => 'admin.website.faqs.index',
            'faq_categories' => 'admin.website.faq-categories.index',
            'seo' => 'admin.website.seo.index',
            'website_media' => 'admin.website.media.index',
        ];
    }

    /**
     * The sidebar labels each CMS module owns (phase-03 §8, F-6.7: one Website group).
     *
     * @return array<string, list<string>> module => labels
     */
    protected function cmsModuleSidebarLabels(): array
    {
        return [
            'website_sections' => ['Website Overview', 'Sections'],
            'menus' => ['Menus'],
            'pages' => ['Pages'],
            'website_cta_blocks' => ['CTA Blocks'],
            'faqs' => ['FAQs'],
            'faq_categories' => ['FAQ Categories'],
            'seo' => ['SEO'],
            'website_media' => ['Media Library'],
        ];
    }

    /**
     * Every table phase-03 creates (§2.1).
     *
     * @return list<string>
     */
    protected function cmsTables(): array
    {
        return [
            'website_sections', 'website_section_items', 'website_section_media', 'menus', 'menu_items', 'pages',
            'cta_blocks', 'faq_categories', 'faqs', 'faq_website_section', 'seo_meta', 'media_assets',
            'cms_revisions', 'sitemap_generations',
        ];
    }

    /**
     * A fingerprint of every CMS row (content, not only counts) plus the audit trail's length, so "the
     * refused request wrote nothing" also catches an UPDATE.
     *
     * @return array<string, string>
     */
    protected function cmsFingerprint(bool $withAudit = true): array
    {
        $print = [];

        foreach ($this->cmsTables() as $table) {
            $rows = DB::table($table)->get()
                ->map(static fn (object $row): string => (string) json_encode($row))
                ->sort()
                ->values()
                ->all();

            $print[$table] = count($rows).':'.md5(implode("\n", $rows));
        }

        if ($withAudit) {
            $print['activity_log'] = DB::table('activity_log')->count().':'.(int) DB::table('activity_log')->max('id');
        }

        return $print;
    }

    /**
     * @return array<string, int>
     */
    protected function cmsRowCounts(): array
    {
        $counts = [];

        foreach ($this->cmsTables() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    */

    protected function cmsSections(): SectionService
    {
        return app(SectionService::class);
    }

    protected function cmsPublisher(): ContentPublisher
    {
        return app(ContentPublisher::class);
    }

    protected function cmsPages(): PageService
    {
        return app(PageService::class);
    }

    protected function cmsMenus(): MenuService
    {
        return app(MenuService::class);
    }

    protected function cmsFaqs(): FaqService
    {
        return app(FaqService::class);
    }

    protected function cmsCtaBlocks(): CtaBlockService
    {
        return app(CtaBlockService::class);
    }

    protected function cmsMedia(): MediaService
    {
        return app(MediaService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The seeded site (phase-03 §6.14)
    |--------------------------------------------------------------------------
    */

    protected function cmsSection(string $key, SectionPlacement $placement = SectionPlacement::Home): WebsiteSection
    {
        /** @var WebsiteSection */
        return WebsiteSection::query()
            ->where('section_key', $key)
            ->where('placement', $placement->value)
            ->whereNull('page_id')
            ->orderBy('id')
            ->firstOrFail();
    }

    protected function cmsMenu(MenuLocation $location = MenuLocation::Header): Menu
    {
        /** @var Menu */
        return Menu::query()->where('location', $location->value)->firstOrFail();
    }

    protected function seededMenuItem(): MenuItem
    {
        /** @var MenuItem */
        return MenuItem::query()
            ->where('menu_id', $this->cmsMenu()->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();
    }

    protected function seededSystemPage(string $slug = 'privacy-policy'): Page
    {
        /** @var Page */
        return Page::query()->where('slug', $slug)->firstOrFail();
    }

    protected function seededCtaBlock(): CtaBlock
    {
        /** @var CtaBlock */
        return CtaBlock::query()->where('key', 'primary')->firstOrFail();
    }

    protected function seededFaqCategory(string $slug = 'general'): FaqCategory
    {
        /** @var FaqCategory */
        return FaqCategory::query()->where('slug', $slug)->firstOrFail();
    }

    protected function seededFaq(): Faq
    {
        /** @var Faq */
        return Faq::query()->orderBy('id')->firstOrFail();
    }

    protected function heroStatistic(): WebsiteSectionItem
    {
        /** @var WebsiteSectionItem */
        return WebsiteSectionItem::query()
            ->where('website_section_id', $this->cmsSection('hero')->getKey())
            ->where('group', 'statistic')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();
    }

    protected function firstRevisionOf(Model $target): CmsRevision
    {
        /** @var CmsRevision */
        return CmsRevision::query()
            ->where('revisionable_type', $target->getMorphClass())
            ->where('revisionable_id', $target->getKey())
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * The live home sections in their current order.
     *
     * @return list<int>
     */
    protected function homeSectionIds(): array
    {
        return WebsiteSection::query()
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * A menu's live two-level tree in the `ReorderMenuRequest` shape, top level reversed.
     *
     * @return list<array{id: int, children: list<array{id: int, children: array<int, never>}>}>
     */
    protected function reversedMenuTree(Menu $menu): array
    {
        $items = MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id']);

        $tree = [];

        foreach ($items->whereNull('parent_id') as $root) {
            $children = [];

            foreach ($items->where('parent_id', $root->id) as $child) {
                $children[] = ['id' => (int) $child->id, 'children' => []];
            }

            $tree[] = ['id' => (int) $root->id, 'children' => $children];
        }

        return array_reverse($tree);
    }

    /*
    |--------------------------------------------------------------------------
    | Fresh fixtures (always through the services)
    |--------------------------------------------------------------------------
    */

    protected function uniqueToken(): string
    {
        return strtolower(Str::random(6)).(++$this->cmsFixtureCounter);
    }

    protected function makeRichContentSection(bool $publish = false): WebsiteSection
    {
        $section = $this->cmsSections()->place('rich_content', SectionPlacement::Home);
        $section = $this->cmsSections()->saveDraft($section, ['heading' => 'Http fixture section '.$this->uniqueToken()]);

        return $publish ? $this->cmsPublisher()->publish($section) : $section;
    }

    protected function makePublishedCtaSection(): WebsiteSection
    {
        $section = $this->cmsSections()->place('cta', SectionPlacement::Home);
        $section = $this->cmsSections()->saveDraft($section, ['cta_ref' => (int) $this->seededCtaBlock()->getKey()]);

        return $this->cmsPublisher()->publish($section);
    }

    protected function addHighlight(WebsiteSection $section): WebsiteSectionItem
    {
        return $this->cmsSections()->upsertItem($section, 'highlight', ['title' => 'Highlight '.$this->uniqueToken()]);
    }

    protected function makeMenuItem(?Menu $menu = null, ?int $parentId = null): MenuItem
    {
        return $this->cmsMenus()->storeItem($menu ?? $this->cmsMenu(), [
            'label' => 'Link '.$this->uniqueToken(),
            'link_type' => MenuItemLinkType::Url->value,
            'url' => 'https://example.com/'.$this->uniqueToken(),
            'parent_id' => $parentId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeCmsPage(bool $publish = false, array $extra = []): Page
    {
        $token = $this->uniqueToken();

        $page = $this->cmsPages()->create(array_merge([
            'title' => 'Http page '.$token,
            'slug' => 'http-page-'.$token,
            'layout' => PageLayout::Content->value,
            'content' => '<p>Http page body '.$token.'.</p>',
        ], $extra));

        return $publish ? $this->cmsPublisher()->publish($page) : $page;
    }

    protected function makeSectionsLayoutPage(): Page
    {
        return $this->makeCmsPage(false, ['layout' => PageLayout::Sections->value, 'content' => null]);
    }

    protected function makeTrashedCmsPage(): Page
    {
        $page = $this->makeCmsPage();
        $this->cmsPages()->delete($page);

        /** @var Page */
        return Page::withTrashed()->findOrFail($page->getKey());
    }

    protected function makeCtaBlock(): CtaBlock
    {
        $token = $this->uniqueToken();

        return $this->cmsCtaBlocks()->save([
            'key' => 'http_'.$token,
            'name' => 'Http CTA '.$token,
            'variant' => 'banner',
            'heading' => 'Http CTA heading '.$token,
        ]);
    }

    protected function makeFaqCategory(): FaqCategory
    {
        $token = $this->uniqueToken();

        return $this->cmsFaqs()->saveCategory(['name' => 'Http category '.$token, 'slug' => 'http-category-'.$token]);
    }

    protected function makeFaq(?FaqCategory $category = null): Faq
    {
        return $this->cmsFaqs()->save([
            'question' => 'Http question '.$this->uniqueToken().'?',
            'answer' => '<p>Http answer.</p>',
            'faq_category_id' => (int) ($category ?? $this->seededFaqCategory())->getKey(),
        ]);
    }

    /**
     * A genuine JPEG with dimensions no other fixture uses, so the checksum dedupe never folds two
     * fixtures into one row.
     */
    protected function fakeCmsImage(): UploadedFile
    {
        $n = ++$this->cmsFixtureCounter;

        return UploadedFile::fake()->image('http-fixture-'.$n.'.jpg', 40 + $n, 30 + $n);
    }

    protected function makeMediaAsset(): MediaAsset
    {
        return $this->cmsMedia()->store($this->fakeCmsImage(), MediaCollection::General, null, ['alt_text' => 'Http fixture image']);
    }

    /**
     * One asset per test for the requests that only need an id to exist.
     */
    protected function sharedMediaAsset(): MediaAsset
    {
        return $this->sharedCmsMediaAsset ??= $this->makeMediaAsset();
    }

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    */

    protected function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function sendCms(string $method, string $url, array $data = [], bool $json = true): TestResponse
    {
        $headers = $json ? ['Accept' => 'application/json'] : [];

        return match (strtoupper($method)) {
            'GET' => $this->get($url, $headers),
            'POST' => $this->post($url, $data, $headers),
            'PUT' => $this->put($url, $data, $headers),
            'PATCH' => $this->patch($url, $data, $headers),
            'DELETE' => $this->delete($url, $data, $headers),
        };
    }

    /**
     * Resolve one route table entry into a concrete request.
     *
     * @param  array{method: string, uri: string, permission: string, kind: string, build: Closure(bool): array<string, mixed>}  $entry
     * @return array{method: string, url: string, data: array<string, mixed>, kind: string}
     */
    protected function prepareCmsRequest(string $name, array $entry, bool $fresh): array
    {
        $built = ($entry['build'])($fresh);

        return [
            'method' => $entry['method'],
            'url' => route($name, $built['params'] ?? []),
            'data' => $built['data'] ?? [],
            'kind' => $entry['kind'],
        ];
    }

    /**
     * Send a prepared request the way its screen or script would: an HTML GET for a screen, JSON for
     * everything a `fetch()` or a form-with-toast posts.
     *
     * @param  array{method: string, url: string, data: array<string, mixed>, kind: string}  $request
     */
    protected function sendPreparedCms(array $request, ?bool $json = null): TestResponse
    {
        return $this->sendCms($request['method'], $request['url'], $request['data'], $json ?? $request['kind'] !== 'screen');
    }

    /**
     * Assert a user who holds the route's permission gets a 200 with a fresh fixture and a valid payload.
     *
     * @param  array{method: string, uri: string, permission: string, kind: string, build: Closure(bool): array<string, mixed>}  $entry
     */
    protected function assertCmsRouteAnswers(User $user, string $name, array $entry, string $context): void
    {
        $request = $this->prepareCmsRequest($name, $entry, $entry['kind'] === 'write');
        $response = $this->actingAs($user)->sendPreparedCms($request);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            sprintf('%s %s (%s) must answer 200 %s; it answered %d: %s', $request['method'], $name, $request['url'], $context, $response->getStatusCode(), Str::limit((string) $response->getContent(), 600)),
        );
    }

    /**
     * Flatten the resolved sidebar to its labels, children included.
     *
     * @return list<string>
     */
    protected function cmsSidebarLabels(User $user): array
    {
        $labels = [];

        foreach (Sidebar::forUser($user) as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $labels[] = (string) $item['label'];

                foreach ($item['children'] ?? [] as $child) {
                    $labels[] = (string) $child['label'];
                }
            }
        }

        return $labels;
    }

    /*
    |--------------------------------------------------------------------------
    | The admin CMS route table — phase-03 §7.1-§7.5
    |--------------------------------------------------------------------------
    */

    /**
     * Route name => method, URI, the one permission its `can:` names, how it answers (`screen` = an HTML
     * view, `json` = a JSON GET, `write` = a JSON write) and the request builder.
     *
     * @return array<string, array{method: string, uri: string, permission: string, kind: string, build: Closure(bool): array<string, mixed>}>
     */
    protected function cmsRouteTable(): array
    {
        $home = SectionPlacement::Home->value;
        $reason = 'Recorded by the phase-03 authorization matrix';

        return [
            // §7.1 Sections
            'admin.website.index' => $this->cmsRoute('GET', 'admin/website', 'website_sections.view_any', 'screen'),
            'admin.website.sections.index' => $this->cmsRoute('GET', 'admin/website/sections/{placement}', 'website_sections.view_any', 'screen',
                static fn (bool $fresh): array => ['params' => ['placement' => $home]]),
            'admin.website.sections.available' => $this->cmsRoute('GET', 'admin/website/sections/{placement}/available', 'website_sections.create', 'screen',
                static fn (bool $fresh): array => ['params' => ['placement' => $home]]),
            'admin.website.sections.store' => $this->cmsRoute('POST', 'admin/website/sections/{placement}', 'website_sections.create', 'write',
                static fn (bool $fresh): array => ['params' => ['placement' => $home], 'data' => ['section_key' => 'rich_content', 'name' => 'Placed by the matrix']]),
            'admin.website.sections.edit' => $this->cmsRoute('GET', 'admin/website/sections/{section}/edit', 'website_sections.view', 'screen',
                fn (bool $fresh): array => ['params' => ['section' => $this->cmsSection('hero')->getKey()]]),
            'admin.website.sections.update' => $this->cmsRoute('PUT', 'admin/website/sections/{section}', 'website_sections.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['section' => $this->cmsSection('hero')->getKey()],
                    'data' => ['content' => ['subtitle' => 'Drafted by the matrix '.$this->uniqueToken()]],
                ]),
            'admin.website.sections.reorder' => $this->cmsRoute('POST', 'admin/website/sections/reorder', 'website_sections.edit', 'write',
                fn (bool $fresh): array => ['data' => ['placement' => $home, 'order' => array_reverse($this->homeSectionIds())]]),
            'admin.website.sections.publish' => $this->cmsRoute('POST', 'admin/website/sections/{section}/publish', 'website_sections.change_status', 'write',
                fn (bool $fresh): array => ['params' => ['section' => $this->cmsSection('hero')->getKey()]]),
            'admin.website.sections.unpublish' => $this->cmsRoute('POST', 'admin/website/sections/{section}/unpublish', 'website_sections.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['section' => ($fresh ? $this->makePublishedCtaSection() : $this->cmsSection('hero'))->getKey()],
                    'data' => ['reason' => $reason],
                ]),
            'admin.website.sections.toggle' => $this->cmsRoute('POST', 'admin/website/sections/{section}/toggle', 'website_sections.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['section' => ($fresh ? $this->makeRichContentSection() : $this->cmsSection('hero'))->getKey()],
                    'data' => ['enabled' => '0'],
                ]),
            'admin.website.sections.duplicate' => $this->cmsRoute('POST', 'admin/website/sections/{section}/duplicate', 'website_sections.create', 'write',
                fn (bool $fresh): array => ['params' => ['section' => ($fresh ? $this->makeRichContentSection() : $this->cmsSection('cta'))->getKey()]]),
            'admin.website.sections.destroy' => $this->cmsRoute('DELETE', 'admin/website/sections/{section}', 'website_sections.delete', 'write',
                fn (bool $fresh): array => [
                    'params' => ['section' => ($fresh ? $this->makeRichContentSection() : $this->cmsSection('cta'))->getKey()],
                    'data' => ['reason' => $reason],
                ]),
            'admin.website.sections.revisions.index' => $this->cmsRoute('GET', 'admin/website/sections/{section}/revisions', 'website_sections.view_logs', 'screen',
                fn (bool $fresh): array => ['params' => ['section' => $this->cmsSection('hero')->getKey()]]),
            'admin.website.sections.revisions.revert' => $this->cmsRoute('POST', 'admin/website/sections/{section}/revisions/{revision}/revert', 'website_sections.change_status', 'write',
                function (bool $fresh) use ($reason): array {
                    $section = $fresh ? $this->makeRichContentSection() : $this->cmsSection('hero');

                    return [
                        'params' => ['section' => $section->getKey(), 'revision' => $this->firstRevisionOf($section)->getKey()],
                        'data' => ['reason' => $reason],
                    ];
                }),
            'admin.website.sections.items.store' => $this->cmsRoute('POST', 'admin/website/sections/{section}/items', 'website_sections.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['section' => ($fresh ? $this->makeRichContentSection() : $this->cmsSection('hero'))->getKey()],
                    'data' => ['group' => 'highlight', 'item' => ['title' => 'Added by the matrix']],
                ]),
            'admin.website.sections.items.reorder' => $this->cmsRoute('POST', 'admin/website/sections/{section}/items/{group}/reorder', 'website_sections.edit', 'write',
                function (bool $fresh): array {
                    if (! $fresh) {
                        return ['params' => ['section' => $this->cmsSection('hero')->getKey(), 'group' => 'statistic'], 'data' => ['order' => [1]]];
                    }

                    $section = $this->makeRichContentSection();
                    $first = $this->addHighlight($section);
                    $second = $this->addHighlight($section);

                    return [
                        'params' => ['section' => $section->getKey(), 'group' => 'highlight'],
                        'data' => ['order' => [(int) $second->getKey(), (int) $first->getKey()]],
                    ];
                }),
            'admin.website.section-items.update' => $this->cmsRoute('PUT', 'admin/website/section-items/{item}', 'website_sections.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['item' => ($fresh ? $this->addHighlight($this->makeRichContentSection()) : $this->heroStatistic())->getKey()],
                    'data' => ['item' => ['title' => 'Renamed by the matrix']],
                ]),
            'admin.website.section-items.toggle' => $this->cmsRoute('POST', 'admin/website/section-items/{item}/toggle', 'website_sections.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['item' => ($fresh ? $this->addHighlight($this->makeRichContentSection()) : $this->heroStatistic())->getKey()],
                    'data' => ['enabled' => '0'],
                ]),
            'admin.website.section-items.destroy' => $this->cmsRoute('DELETE', 'admin/website/section-items/{item}', 'website_sections.edit', 'write',
                fn (bool $fresh): array => ['params' => ['item' => ($fresh ? $this->addHighlight($this->makeRichContentSection()) : $this->heroStatistic())->getKey()]]),
            'admin.website.cache.flush' => $this->cmsRoute('POST', 'admin/website/cache/flush', 'website_sections.change_status', 'write'),
            'admin.website.statistics.index' => $this->cmsRoute('GET', 'admin/website/statistics', 'website_sections.view_any', 'screen'),

            // §7.2 Menus
            'admin.website.menus.index' => $this->cmsRoute('GET', 'admin/website/menus', 'menus.view_any', 'screen'),
            'admin.website.menus.show' => $this->cmsRoute('GET', 'admin/website/menus/{menu}', 'menus.view', 'screen',
                fn (bool $fresh): array => ['params' => ['menu' => $this->cmsMenu()->getKey()]]),
            'admin.website.menus.update' => $this->cmsRoute('PUT', 'admin/website/menus/{menu}', 'menus.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['menu' => $this->cmsMenu()->getKey()],
                    'data' => ['description' => 'Described by the matrix '.$this->uniqueToken()],
                ]),
            'admin.website.menus.items.store' => $this->cmsRoute('POST', 'admin/website/menus/{menu}/items', 'menus.create', 'write',
                fn (bool $fresh): array => [
                    'params' => ['menu' => $this->cmsMenu()->getKey()],
                    'data' => ['label' => 'Matrix link', 'link_type' => MenuItemLinkType::Url->value, 'url' => 'https://example.com/matrix'],
                ]),
            'admin.website.menu-items.update' => $this->cmsRoute('PUT', 'admin/website/menu-items/{item}', 'menus.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['item' => ($fresh ? $this->makeMenuItem() : $this->seededMenuItem())->getKey()],
                    'data' => ['label' => 'Renamed by the matrix', 'link_type' => MenuItemLinkType::Url->value, 'url' => 'https://example.com/renamed'],
                ]),
            'admin.website.menu-items.toggle' => $this->cmsRoute('POST', 'admin/website/menu-items/{item}/toggle', 'menus.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['item' => ($fresh ? $this->makeMenuItem() : $this->seededMenuItem())->getKey()],
                    'data' => ['enabled' => '0'],
                ]),
            'admin.website.menu-items.destroy' => $this->cmsRoute('DELETE', 'admin/website/menu-items/{item}', 'menus.delete', 'write',
                fn (bool $fresh): array => ['params' => ['item' => ($fresh ? $this->makeMenuItem() : $this->seededMenuItem())->getKey()]]),
            'admin.website.menus.reorder' => $this->cmsRoute('POST', 'admin/website/menus/{menu}/reorder', 'menus.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['menu' => $this->cmsMenu()->getKey()],
                    'data' => ['tree' => $this->reversedMenuTree($this->cmsMenu())],
                ]),
            'admin.website.menus.link-check' => $this->cmsRoute('GET', 'admin/website/menus/{menu}/link-check', 'menus.view', 'screen',
                fn (bool $fresh): array => ['params' => ['menu' => $this->cmsMenu()->getKey()]]),

            // §7.3 Pages
            'admin.website.pages.index' => $this->cmsRoute('GET', 'admin/website/pages', 'pages.view_any', 'screen'),
            'admin.website.pages.create' => $this->cmsRoute('GET', 'admin/website/pages/create', 'pages.create', 'screen'),
            'admin.website.pages.store' => $this->cmsRoute('POST', 'admin/website/pages', 'pages.create', 'write',
                fn (bool $fresh): array => ['data' => [
                    'title' => 'Matrix page '.$this->uniqueToken(),
                    'layout' => PageLayout::Content->value,
                    'content' => '<p>Written by the matrix.</p>',
                ]]),
            'admin.website.pages.edit' => $this->cmsRoute('GET', 'admin/website/pages/{page}/edit', 'pages.view', 'screen',
                fn (bool $fresh): array => ['params' => ['page' => $this->seededSystemPage()->getKey()]]),
            'admin.website.pages.update' => $this->cmsRoute('PUT', 'admin/website/pages/{page}', 'pages.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['page' => ($fresh ? $this->makeCmsPage() : $this->seededSystemPage())->getKey()],
                    'data' => ['content' => '<p>Edited by the matrix.</p>'],
                ]),
            'admin.website.pages.publish' => $this->cmsRoute('POST', 'admin/website/pages/{page}/publish', 'pages.change_status', 'write',
                fn (bool $fresh): array => ['params' => ['page' => ($fresh ? $this->makeCmsPage() : $this->seededSystemPage())->getKey()]]),
            'admin.website.pages.schedule' => $this->cmsRoute('POST', 'admin/website/pages/{page}/schedule', 'pages.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['page' => ($fresh ? $this->makeCmsPage() : $this->seededSystemPage())->getKey()],
                    'data' => ['publish_at' => Carbon::now()->addDays(3)->format('Y-m-d H:i')],
                ]),
            'admin.website.pages.unpublish' => $this->cmsRoute('POST', 'admin/website/pages/{page}/unpublish', 'pages.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['page' => ($fresh ? $this->makeCmsPage(true) : $this->seededSystemPage())->getKey()],
                    'data' => ['reason' => $reason],
                ]),
            'admin.website.pages.duplicate' => $this->cmsRoute('POST', 'admin/website/pages/{page}/duplicate', 'pages.create', 'write',
                fn (bool $fresh): array => ['params' => ['page' => ($fresh ? $this->makeCmsPage() : $this->seededSystemPage())->getKey()]]),
            'admin.website.pages.destroy' => $this->cmsRoute('DELETE', 'admin/website/pages/{page}', 'pages.delete', 'write',
                fn (bool $fresh): array => ['params' => ['page' => ($fresh ? $this->makeCmsPage() : $this->seededSystemPage())->getKey()]]),
            'admin.website.pages.restore' => $this->cmsRoute('POST', 'admin/website/pages/{page}/restore', 'pages.restore', 'write',
                fn (bool $fresh): array => ['params' => ['page' => ($fresh ? $this->makeTrashedCmsPage() : $this->seededSystemPage())->getKey()]]),
            'admin.website.pages.revisions.index' => $this->cmsRoute('GET', 'admin/website/pages/{page}/revisions', 'pages.view_logs', 'screen',
                fn (bool $fresh): array => ['params' => ['page' => $this->seededSystemPage()->getKey()]]),
            'admin.website.pages.revisions.revert' => $this->cmsRoute('POST', 'admin/website/pages/{page}/revisions/{revision}/revert', 'pages.change_status', 'write',
                function (bool $fresh) use ($reason): array {
                    $page = $fresh ? $this->makeCmsPage() : $this->seededSystemPage();

                    return [
                        'params' => ['page' => $page->getKey(), 'revision' => $this->firstRevisionOf($page)->getKey()],
                        'data' => ['reason' => $reason],
                    ];
                }),
            'admin.website.pages.preview-link' => $this->cmsRoute('GET', 'admin/website/pages/{page}/preview-link', 'pages.view', 'json',
                fn (bool $fresh): array => ['params' => ['page' => $this->seededSystemPage()->getKey()]]),
            'admin.website.pages.export' => $this->cmsRoute('GET', 'admin/website/pages/export', 'pages.export', 'screen'),

            // §7.4 CTA blocks
            'admin.website.cta-blocks.index' => $this->cmsRoute('GET', 'admin/website/cta-blocks', 'website_cta_blocks.view_any', 'screen'),
            'admin.website.cta-blocks.store' => $this->cmsRoute('POST', 'admin/website/cta-blocks', 'website_cta_blocks.create', 'write',
                fn (bool $fresh): array => ['data' => [
                    'key' => 'matrix_'.$this->uniqueToken(),
                    'name' => 'Matrix CTA',
                    'variant' => 'banner',
                    'heading' => 'Created by the matrix',
                ]]),
            'admin.website.cta-blocks.edit' => $this->cmsRoute('GET', 'admin/website/cta-blocks/{ctaBlock}/edit', 'website_cta_blocks.view', 'screen',
                fn (bool $fresh): array => ['params' => ['ctaBlock' => $this->seededCtaBlock()->getKey()]]),
            'admin.website.cta-blocks.update' => $this->cmsRoute('PUT', 'admin/website/cta-blocks/{ctaBlock}', 'website_cta_blocks.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['ctaBlock' => ($fresh ? $this->makeCtaBlock() : $this->seededCtaBlock())->getKey()],
                    'data' => ['name' => 'Renamed by the matrix'],
                ]),
            'admin.website.cta-blocks.toggle' => $this->cmsRoute('POST', 'admin/website/cta-blocks/{ctaBlock}/toggle', 'website_cta_blocks.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['ctaBlock' => ($fresh ? $this->makeCtaBlock() : $this->seededCtaBlock())->getKey()],
                    'data' => ['status' => 'published'],
                ]),
            'admin.website.cta-blocks.usage' => $this->cmsRoute('GET', 'admin/website/cta-blocks/{ctaBlock}/usage', 'website_cta_blocks.view', 'screen',
                fn (bool $fresh): array => ['params' => ['ctaBlock' => $this->seededCtaBlock()->getKey()]]),
            'admin.website.cta-blocks.destroy' => $this->cmsRoute('DELETE', 'admin/website/cta-blocks/{ctaBlock}', 'website_cta_blocks.delete', 'write',
                fn (bool $fresh): array => ['params' => ['ctaBlock' => ($fresh ? $this->makeCtaBlock() : $this->seededCtaBlock())->getKey()]]),

            // §7.4 FAQs
            'admin.website.faqs.index' => $this->cmsRoute('GET', 'admin/website/faqs', 'faqs.view_any', 'screen'),
            'admin.website.faqs.store' => $this->cmsRoute('POST', 'admin/website/faqs', 'faqs.create', 'write',
                fn (bool $fresh): array => ['data' => [
                    'question' => 'Asked by the matrix?',
                    'answer' => '<p>Answered by the matrix.</p>',
                    'faq_category_id' => $this->seededFaqCategory()->getKey(),
                ]]),
            'admin.website.faqs.update' => $this->cmsRoute('PUT', 'admin/website/faqs/{faq}', 'faqs.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['faq' => ($fresh ? $this->makeFaq() : $this->seededFaq())->getKey()],
                    'data' => ['question' => 'Renamed by the matrix?'],
                ]),
            'admin.website.faqs.toggle' => $this->cmsRoute('POST', 'admin/website/faqs/{faq}/toggle', 'faqs.change_status', 'write',
                fn (bool $fresh): array => [
                    'params' => ['faq' => ($fresh ? $this->makeFaq() : $this->seededFaq())->getKey()],
                    'data' => ['status' => 'published'],
                ]),
            'admin.website.faqs.reorder' => $this->cmsRoute('POST', 'admin/website/faqs/reorder', 'faqs.edit', 'write',
                function (bool $fresh): array {
                    $category = $this->seededFaqCategory();
                    $ids = Faq::query()->where('faq_category_id', $category->getKey())->orderBy('sort_order')->orderBy('id')->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)->all();

                    return ['data' => ['faq_category_id' => $category->getKey(), 'order' => array_reverse($ids)]];
                }),
            'admin.website.faqs.destroy' => $this->cmsRoute('DELETE', 'admin/website/faqs/{faq}', 'faqs.delete', 'write',
                fn (bool $fresh): array => ['params' => ['faq' => ($fresh ? $this->makeFaq() : $this->seededFaq())->getKey()]]),

            // §7.4 FAQ categories
            'admin.website.faq-categories.index' => $this->cmsRoute('GET', 'admin/website/faq-categories', 'faq_categories.view_any', 'screen'),
            'admin.website.faq-categories.store' => $this->cmsRoute('POST', 'admin/website/faq-categories', 'faq_categories.create', 'write',
                fn (bool $fresh): array => ['data' => ['name' => 'Matrix category '.$this->uniqueToken()]]),
            'admin.website.faq-categories.update' => $this->cmsRoute('PUT', 'admin/website/faq-categories/{category}', 'faq_categories.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['category' => ($fresh ? $this->makeFaqCategory() : $this->seededFaqCategory())->getKey()],
                    'data' => ['name' => 'Renamed by the matrix'],
                ]),
            'admin.website.faq-categories.reorder' => $this->cmsRoute('POST', 'admin/website/faq-categories/reorder', 'faq_categories.edit', 'write',
                fn (bool $fresh): array => ['data' => ['order' => array_reverse(
                    FaqCategory::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)->all()
                )]]),
            'admin.website.faq-categories.destroy' => $this->cmsRoute('DELETE', 'admin/website/faq-categories/{category}', 'faq_categories.delete', 'write',
                fn (bool $fresh): array => ['params' => ['category' => ($fresh ? $this->makeFaqCategory() : $this->seededFaqCategory())->getKey()]]),

            // §7.5 SEO
            'admin.website.seo.index' => $this->cmsRoute('GET', 'admin/website/seo', 'seo.view_any', 'screen'),
            'admin.website.seo.edit' => $this->cmsRoute('GET', 'admin/website/seo/edit', 'seo.view', 'screen',
                static fn (bool $fresh): array => ['params' => ['target' => 'route:site.home']]),
            'admin.website.seo.update' => $this->cmsRoute('PUT', 'admin/website/seo', 'seo.edit', 'write',
                fn (bool $fresh): array => ['data' => ['target' => 'route:site.home', 'seo' => ['title' => 'Home title from the matrix '.$this->uniqueToken()]]]),
            'admin.website.seo.bulk-robots' => $this->cmsRoute('POST', 'admin/website/seo/bulk-robots', 'seo.edit', 'write',
                static fn (bool $fresh): array => ['data' => ['targets' => ['route:site.home'], 'robots' => 'index_follow']]),
            'admin.website.seo.sitemap.regenerate' => $this->cmsRoute('POST', 'admin/website/seo/sitemap/regenerate', 'seo.edit', 'write'),
            'admin.website.seo.sitemap.history' => $this->cmsRoute('GET', 'admin/website/seo/sitemap/history', 'seo.view', 'screen'),
            'admin.website.seo.robots.preview' => $this->cmsRoute('GET', 'admin/website/seo/robots/preview', 'seo.view', 'screen'),
            'admin.website.seo.export' => $this->cmsRoute('GET', 'admin/website/seo/export', 'seo.export', 'screen'),

            // §7.5 Media library
            'admin.website.media.index' => $this->cmsRoute('GET', 'admin/website/media', 'website_media.view_any', 'screen'),
            'admin.website.media.store' => $this->cmsRoute('POST', 'admin/website/media', 'website_media.upload', 'write',
                fn (bool $fresh): array => ['data' => $fresh ? ['file' => $this->fakeCmsImage(), 'alt_text' => 'Uploaded by the matrix'] : []]),
            'admin.website.media.show' => $this->cmsRoute('GET', 'admin/website/media/{asset}', 'website_media.view', 'screen',
                fn (bool $fresh): array => ['params' => ['asset' => $this->sharedMediaAsset()->getKey()]]),
            'admin.website.media.update' => $this->cmsRoute('PUT', 'admin/website/media/{asset}', 'website_media.edit', 'write',
                fn (bool $fresh): array => [
                    'params' => ['asset' => ($fresh ? $this->makeMediaAsset() : $this->sharedMediaAsset())->getKey()],
                    'data' => ['alt_text' => 'Described by the matrix'],
                ]),
            'admin.website.media.usage' => $this->cmsRoute('GET', 'admin/website/media/{asset}/usage', 'website_media.view', 'screen',
                fn (bool $fresh): array => ['params' => ['asset' => $this->sharedMediaAsset()->getKey()]]),
            'admin.website.media.regenerate' => $this->cmsRoute('POST', 'admin/website/media/{asset}/regenerate', 'website_media.edit', 'write',
                fn (bool $fresh): array => ['params' => ['asset' => ($fresh ? $this->makeMediaAsset() : $this->sharedMediaAsset())->getKey()]]),
            'admin.website.media.destroy' => $this->cmsRoute('DELETE', 'admin/website/media/{asset}', 'website_media.delete', 'write',
                fn (bool $fresh): array => ['params' => ['asset' => ($fresh ? $this->makeMediaAsset() : $this->sharedMediaAsset())->getKey()]]),
        ];
    }

    /**
     * @param  (Closure(bool): array<string, mixed>)|null  $build
     * @return array{method: string, uri: string, permission: string, kind: string, build: Closure(bool): array<string, mixed>}
     */
    private function cmsRoute(string $method, string $uri, string $permission, string $kind, ?Closure $build = null): array
    {
        return [
            'method' => $method,
            'uri' => $uri,
            'permission' => $permission,
            'kind' => $kind,
            'build' => $build ?? static fn (bool $fresh): array => [],
        ];
    }
}
