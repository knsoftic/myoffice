<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\User;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use App\Support\Cms\SectionRegistry;
use App\Support\SettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Form Request validation for every phase-03 admin CMS write, including hostile input (CLAUDE.md §8 item 3,
 * phase-03 §6.6, INV-3, INV-11, INV-13).
 *
 * Every refusal is asserted three ways: the status is **422** (never a 500 from a TypeError or a raw
 * database error), the error names the offending field, and **nothing was written** (every CMS row and
 * the audit trail are fingerprinted around the request). The actor is a Super Admin on purpose: holding
 * every permission and bypassing every policy, only validation stands between the payload and the data.
 *
 * The contract rows exercised through the forms: FT-01, FT-05 (placing), FT-10 (the unpublish reason),
 * FT-14 (slugs), FT-18 (repeater bounds), FT-19 / FT-20 (menu depth and cycles), FT-29 (unsafe URLs) and
 * FT-33 (uploads judged by content).
 */
final class CmsFormValidationTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** A section type this file registers at runtime to exercise a repeater `min` of 1. */
    private const BOUNDED_TYPE = 'http_bounded_list';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');

        $this->superAdmin = $this->createSuperAdmin();
    }

    protected function tearDown(): void
    {
        SectionRegistry::flush();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Hostile input on every write
    |--------------------------------------------------------------------------
    */

    public function test_hostile_input_on_every_cms_write_is_a_422_that_writes_nothing(): void
    {
        $hero = $this->cmsSection('hero');
        $rich = $this->makeRichContentSection();
        $richRevision = $this->firstRevisionOf($rich);
        $statistic = $this->heroStatistic();
        $header = $this->cmsMenu();
        $legalItem = MenuItem::query()->where('menu_id', $this->cmsMenu(MenuLocation::FooterLegal)->getKey())->orderBy('id')->firstOrFail();
        $headerItem = $this->makeMenuItem($header);
        $draftPage = $this->makeCmsPage();
        $publishedPage = $this->makeCmsPage(true);
        $pageRevision = $this->firstRevisionOf($draftPage);
        $cta = $this->makeCtaBlock();
        $faq = $this->makeFaq();
        $general = $this->seededFaqCategory();
        $asset = $this->makeMediaAsset();

        $validUrlItem = ['label' => 'A safe link', 'link_type' => MenuItemLinkType::Url->value, 'url' => 'https://example.com/safe'];
        $validPage = ['title' => 'A valid page title', 'layout' => 'content'];
        $validCta = ['key' => 'hostile_'.$this->uniqueToken(), 'name' => 'Hostile CTA', 'variant' => 'banner', 'heading' => 'Hostile heading'];
        $validFaq = ['question' => 'A valid question?', 'answer' => '<p>A valid answer.</p>'];
        $validStatistic = ['label' => 'Awards', 'value_mode' => 'manual', 'manual_value' => '10'];

        $sectionStore = route('admin.website.sections.store', ['placement' => 'home']);
        $sectionUpdate = route('admin.website.sections.update', $hero);
        $itemStore = route('admin.website.sections.items.store', $hero);
        $menuItemStore = route('admin.website.menus.items.store', $header);
        $pageStore = route('admin.website.pages.store');
        $pageUpdate = route('admin.website.pages.update', $draftPage);
        $ctaStore = route('admin.website.cta-blocks.store');
        $faqStore = route('admin.website.faqs.store');
        $categoryStore = route('admin.website.faq-categories.store');
        $seoUpdate = route('admin.website.seo.update');
        $bulkRobots = route('admin.website.seo.bulk-robots');

        $cases = [
            // §7.1 sections
            'section type as an array' => ['POST', $sectionStore, ['section_key' => ['hero']], 'section_key'],
            'section name as an array' => ['POST', $sectionStore, ['section_key' => 'rich_content', 'name' => ['x']], 'name'],
            'a page section without its page' => ['POST', route('admin.website.sections.store', ['placement' => 'page']), ['section_key' => 'rich_content'], 'page_id'],
            'a home section claiming a page' => ['POST', $sectionStore, ['section_key' => 'rich_content', 'page_id' => $draftPage->getKey()], 'page_id'],
            'content as a string' => ['PUT', $sectionUpdate, ['content' => 'not-an-array'], 'content'],
            'a heading as an array' => ['PUT', $sectionUpdate, ['content' => ['heading' => ['x']]], 'content.heading'],
            'a javascript: button URL' => ['PUT', $sectionUpdate, ['content' => ['primary_button' => ['label' => 'Go', 'url' => 'javascript:alert(1)']]], 'content.primary_button.url'],
            'a data: button URL' => ['PUT', $sectionUpdate, ['content' => ['primary_button' => ['label' => 'Go', 'url' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==']]], 'content.primary_button.url'],
            'a protocol-relative button URL' => ['PUT', $sectionUpdate, ['content' => ['primary_button' => ['label' => 'Go', 'url' => '//evil.example/phish']]], 'content.primary_button.url'],
            'a content key the type does not declare' => ['PUT', $sectionUpdate, ['content' => ['injected_script' => '<script>alert(1)</script>']], 'content'],
            'an image slot given text' => ['PUT', $sectionUpdate, ['media' => ['hero_image' => 'abc']], 'media.hero_image'],
            'an image slot pointing nowhere' => ['PUT', $sectionUpdate, ['media' => ['hero_image' => 999999999]], 'media.hero_image'],
            'an image slot the type does not declare' => ['PUT', $sectionUpdate, ['media' => ['not_a_slot' => $asset->getKey()]], 'media'],
            'an anchor that is not a slug' => ['PUT', $sectionUpdate, ['anchor' => 'Not An Anchor!'], 'anchor'],
            'hand-picked questions on a hero' => ['PUT', $sectionUpdate, ['faqs' => [$faq->getKey()]], 'faqs'],
            'an overlay above its bound' => ['PUT', $sectionUpdate, ['content' => ['overlay_opacity' => 95]], 'content.overlay_opacity'],
            'an alignment outside its options' => ['PUT', $sectionUpdate, ['content' => ['alignment' => 'diagonal']], 'content.alignment'],
            'section order as a string' => ['POST', route('admin.website.sections.reorder'), ['placement' => 'home', 'order' => '1,2,3'], 'order'],
            'section order with a non-id' => ['POST', route('admin.website.sections.reorder'), ['placement' => 'home', 'order' => ['abc']], 'order.0'],
            'section order with a duplicate' => ['POST', route('admin.website.sections.reorder'), ['placement' => 'home', 'order' => [5, 5]], 'order.0'],
            'an unknown placement to reorder' => ['POST', route('admin.website.sections.reorder'), ['placement' => 'nowhere', 'order' => [1]], 'placement'],
            'a stale section order (FT-15)' => ['POST', route('admin.website.sections.reorder'), ['placement' => 'home', 'order' => [(int) $hero->getKey()]], 'order'],
            'unpublish with no reason (FT-10)' => ['POST', route('admin.website.sections.unpublish', $hero), [], 'reason'],
            'unpublish with a too-short reason (FT-10)' => ['POST', route('admin.website.sections.unpublish', $hero), ['reason' => 'abc'], 'reason'],
            'unpublish with a reason array' => ['POST', route('admin.website.sections.unpublish', $hero), ['reason' => ['x']], 'reason'],
            'toggle with no state' => ['POST', route('admin.website.sections.toggle', $hero), [], 'enabled'],
            'toggle with a non-boolean' => ['POST', route('admin.website.sections.toggle', $hero), ['enabled' => 'maybe'], 'enabled'],
            'remove with no reason' => ['DELETE', route('admin.website.sections.destroy', $rich), [], 'reason'],
            'revert with no reason' => ['POST', route('admin.website.sections.revisions.revert', ['section' => $rich->getKey(), 'revision' => $richRevision->getKey()]), [], 'reason'],
            'an item group that is a path' => ['POST', $itemStore, ['group' => '../../etc', 'item' => $validStatistic], 'group'],
            'an item group the type does not keep' => ['POST', $itemStore, ['group' => 'history', 'item' => ['year' => 2020, 'title' => 'x']], 'group'],
            'an item as a string' => ['POST', $itemStore, ['group' => 'statistic', 'item' => 'string'], 'item'],
            'an item label as an array' => ['POST', $itemStore, ['group' => 'statistic', 'item' => ['label' => ['x'], 'value_mode' => 'manual']], 'item.label'],
            'a negative statistic (FT-31)' => ['POST', $itemStore, ['group' => 'statistic', 'item' => ['label' => 'Awards', 'value_mode' => 'manual', 'manual_value' => '-5']], 'item.manual_value'],
            'a statistic mode outside its options' => ['POST', $itemStore, ['group' => 'statistic', 'item' => ['label' => 'Awards', 'value_mode' => 'sometimes']], 'item.value_mode'],
            'an item field the repeater does not declare' => ['POST', $itemStore, ['group' => 'statistic', 'item' => $validStatistic + ['onclick' => 'alert(1)']], 'item'],
            'a live statistic that names no metric' => ['POST', $itemStore, ['group' => 'statistic', 'item' => ['label' => 'Live', 'value_mode' => 'auto']], 'items.statistic.metric'],
            'moving an item to another repeater' => ['PUT', route('admin.website.section-items.update', $statistic), ['group' => 'history', 'item' => $validStatistic], 'group'],
            'item order missing' => ['POST', route('admin.website.sections.items.reorder', ['section' => $hero->getKey(), 'group' => 'statistic']), [], 'order'],
            'item toggle with no state' => ['POST', route('admin.website.section-items.toggle', $statistic), [], 'enabled'],

            // §7.2 menus
            'a menu slug change' => ['PUT', route('admin.website.menus.update', $header), ['slug' => 'hijacked'], 'slug'],
            'a menu location change' => ['PUT', route('admin.website.menus.update', $header), ['location' => MenuLocation::FooterLegal->value], 'location'],
            'a menu name as an array' => ['PUT', route('admin.website.menus.update', $header), ['name' => ['x']], 'name'],
            'a javascript: menu URL (FT-29)' => ['POST', $menuItemStore, ['url' => 'javascript:alert(1)'] + $validUrlItem, 'url'],
            'a protocol-relative menu URL' => ['POST', $menuItemStore, ['url' => '//evil.example'] + $validUrlItem, 'url'],
            'a data: menu URL' => ['POST', $menuItemStore, ['url' => 'data:text/html,<script>alert(1)</script>'] + $validUrlItem, 'url'],
            'a vbscript: menu URL' => ['POST', $menuItemStore, ['url' => 'vbscript:msgbox(1)'] + $validUrlItem, 'url'],
            'an unknown link type' => ['POST', $menuItemStore, ['link_type' => 'bogus'] + $validUrlItem, 'link_type'],
            'a route that does not exist' => ['POST', $menuItemStore, ['label' => 'Nowhere', 'link_type' => MenuItemLinkType::Route->value, 'route_name' => 'no.such.route'], 'route_name'],
            'a page link given text' => ['POST', $menuItemStore, ['label' => 'Page', 'link_type' => MenuItemLinkType::Page->value, 'page_id' => 'abc'], 'page_id'],
            'a posted menu depth' => ['POST', $menuItemStore, $validUrlItem + ['depth' => 2], 'depth'],
            'a posted menu id' => ['POST', $menuItemStore, $validUrlItem + ['menu_id' => $legalItem->menu_id], 'menu_id'],
            'a menu label as an array' => ['POST', $menuItemStore, ['label' => ['x']] + $validUrlItem, 'label'],
            'a visibility outside its options' => ['POST', $menuItemStore, $validUrlItem + ['visibility' => 'everyone'], 'visibility'],
            'markup as an icon' => ['POST', $menuItemStore, $validUrlItem + ['icon' => '<svg onload=alert(1)>'], 'icon'],
            'a parent from another menu' => ['POST', $menuItemStore, $validUrlItem + ['parent_id' => $legalItem->getKey()], 'parent_id'],
            'a menu tree as a string' => ['POST', route('admin.website.menus.reorder', $header), ['tree' => 'x'], 'tree'],
            'a menu tree node with an extra key' => ['POST', route('admin.website.menus.reorder', $header), ['tree' => [['id' => $headerItem->getKey(), 'children' => [], 'evil' => 1]]], 'tree.0'],
            'a menu toggle with a non-boolean' => ['POST', route('admin.website.menu-items.toggle', $headerItem), ['enabled' => 'x'], 'enabled'],

            // §7.3 pages
            'a page title as an array' => ['POST', $pageStore, ['title' => ['x'], 'layout' => 'content'], 'title'],
            'a slug that is not a slug' => ['POST', $pageStore, $validPage + ['slug' => 'Not A Slug!'], 'slug'],
            'a template that is a path' => ['POST', $pageStore, $validPage + ['template' => '../../../../etc/passwd'], 'template'],
            'a template outside the allowlist' => ['POST', $pageStore, $validPage + ['template' => 'errors.500'], 'template'],
            'a layout outside its options' => ['POST', $pageStore, ['title' => 'Grid page', 'layout' => 'grid'], 'layout'],
            'a posted status' => ['POST', $pageStore, $validPage + ['status' => 'published'], 'status'],
            'a posted publish date' => ['POST', $pageStore, $validPage + ['published_at' => '2020-01-01 00:00:00'], 'published_at'],
            'a posted system flag' => ['POST', $pageStore, $validPage + ['is_system' => '1'], 'is_system'],
            'a posted live body' => ['POST', $pageStore, $validPage + ['published_content' => '<p>live</p>'], 'published_content'],
            'a posted content hash' => ['POST', $pageStore, $validPage + ['content_hash' => str_repeat('a', 40)], 'content_hash'],
            'a javascript: canonical URL' => ['POST', $pageStore, $validPage + ['seo' => ['canonical_url' => 'javascript:alert(1)']], 'seo.canonical_url'],
            'a robots value outside its options' => ['POST', $pageStore, $validPage + ['seo' => ['robots' => 'please_index']], 'seo.robots'],
            'a meta description longer than its column' => ['POST', $pageStore, $validPage + ['seo' => ['meta_description' => str_repeat('d', 321)]], 'seo.meta_description'],
            'an SEO field the store does not declare' => ['POST', $pageStore, $validPage + ['seo' => ['evil' => 'x']], 'seo'],
            'a banner that does not exist' => ['POST', $pageStore, $validPage + ['banner_media_id' => 999999999], 'banner_media_id'],
            'a publish flag that is not a boolean' => ['PUT', $pageUpdate, ['publish' => 'yes-please'], 'publish'],
            'a reserved slug on update (FT-14)' => ['PUT', $pageUpdate, ['slug' => 'login'], 'slug'],
            'a schedule in the past' => ['POST', route('admin.website.pages.schedule', $draftPage), ['publish_at' => '2020-01-01 10:00'], 'publish_at'],
            'a schedule that is not a date' => ['POST', route('admin.website.pages.schedule', $draftPage), ['publish_at' => 'not a date'], 'publish_at'],
            'a schedule as an array' => ['POST', route('admin.website.pages.schedule', $draftPage), ['publish_at' => ['x']], 'publish_at'],
            'unpublish a page with no reason' => ['POST', route('admin.website.pages.unpublish', $publishedPage), [], 'reason'],
            'revert a page with no reason' => ['POST', route('admin.website.pages.revisions.revert', ['page' => $draftPage->getKey(), 'revision' => $pageRevision->getKey()]), [], 'reason'],

            // §7.4 CTA blocks, FAQs, FAQ categories
            'a CTA key that is not a key' => ['POST', $ctaStore, ['key' => 'Not A Key'] + $validCta, 'key'],
            'a CTA key already taken' => ['POST', $ctaStore, ['key' => 'primary'] + $validCta, 'key'],
            'a javascript: CTA button URL' => ['POST', $ctaStore, $validCta + ['primary_url' => 'javascript:alert(1)'], 'primary_url'],
            'a CTA colour that is not hex' => ['POST', $ctaStore, $validCta + ['background_color' => 'red; background:url(x)'], 'background_color'],
            'a posted CTA status' => ['POST', $ctaStore, $validCta + ['status' => 'published'], 'status'],
            'a posted CTA usage count' => ['POST', $ctaStore, $validCta + ['usage_count' => 0], 'usage_count'],
            'a CTA variant outside its options' => ['POST', $ctaStore, ['variant' => 'hologram'] + $validCta, 'variant'],
            'a CTA toggle to scheduled' => ['POST', route('admin.website.cta-blocks.toggle', $cta), ['status' => 'scheduled'], 'status'],
            'a CTA toggle with no status' => ['POST', route('admin.website.cta-blocks.toggle', $cta), [], 'status'],
            'a question as an array' => ['POST', $faqStore, ['question' => ['x'], 'answer' => '<p>x</p>'], 'question'],
            'a posted course owner' => ['POST', $faqStore, $validFaq + ['faqable_type' => User::class, 'faqable_id' => 1], 'faqable_type'],
            'a posted question status' => ['POST', $faqStore, $validFaq + ['status' => 'published'], 'status'],
            'a category that does not exist' => ['POST', $faqStore, $validFaq + ['faq_category_id' => 999999999], 'faq_category_id'],
            'a question toggle to scheduled' => ['POST', route('admin.website.faqs.toggle', $faq), ['status' => 'scheduled'], 'status'],
            'a question order without its bucket' => ['POST', route('admin.website.faqs.reorder'), ['order' => [$faq->getKey()]], 'faq_category_id'],
            'a question order with a non-id' => ['POST', route('admin.website.faqs.reorder'), ['faq_category_id' => $general->getKey(), 'order' => ['abc']], 'order.0'],
            'a category slug that is not a slug' => ['POST', $categoryStore, ['name' => 'Category', 'slug' => 'Bad Slug'], 'slug'],
            'a category slug already taken' => ['POST', $categoryStore, ['name' => 'Category', 'slug' => 'general'], 'slug'],
            'markup as a category icon' => ['POST', $categoryStore, ['name' => 'Category', 'icon' => '<svg>'], 'icon'],
            'a posted category position' => ['POST', $categoryStore, ['name' => 'Category', 'sort_order' => 1], 'sort_order'],
            'a category order missing' => ['POST', route('admin.website.faq-categories.reorder'), [], 'order'],

            // §7.5 SEO and media
            'an SEO target that is not a target' => ['PUT', $seoUpdate, ['target' => 'page:abc', 'seo' => ['title' => 'x']], 'target'],
            'an SEO save with no target' => ['PUT', $seoUpdate, ['seo' => ['title' => 'x']], 'target'],
            'an SEO save with no values' => ['PUT', $seoUpdate, ['target' => 'route:site.home'], 'seo'],
            'a javascript: canonical on the SEO screen' => ['PUT', $seoUpdate, ['target' => 'route:site.home', 'seo' => ['canonical_url' => 'javascript:alert(1)']], 'seo.canonical_url'],
            'a sitemap priority above 1' => ['PUT', $seoUpdate, ['target' => 'route:site.home', 'seo' => ['sitemap_priority' => '2']], 'seo.sitemap_priority'],
            'an OG type outside its options' => ['PUT', $seoUpdate, ['target' => 'route:site.home', 'seo' => ['og_type' => 'malware']], 'seo.og_type'],
            'an SEO field the screen does not declare' => ['PUT', $seoUpdate, ['target' => 'route:site.home', 'seo' => ['injected' => 'x']], 'seo'],
            'a bulk change with no targets' => ['POST', $bulkRobots, ['robots' => 'noindex_follow'], 'targets'],
            'a bulk robots value outside its options' => ['POST', $bulkRobots, ['targets' => ['route:site.home'], 'robots' => 'bogus'], 'robots'],
            'a bulk change that changes nothing' => ['POST', $bulkRobots, ['targets' => ['route:site.home']], 'robots'],
            'a bulk target that does not exist' => ['POST', $bulkRobots, ['targets' => ['page:999999999'], 'robots' => 'index_follow'], 'targets'],
            'an upload with no file' => ['POST', route('admin.website.media.store'), [], 'file'],
            'an upload that is text' => ['POST', route('admin.website.media.store'), ['file' => 'not-a-file'], 'file'],
            'a media checksum edit' => ['PUT', route('admin.website.media.update', $asset), ['checksum' => str_repeat('0', 64)], 'checksum'],
            'a media path edit' => ['PUT', route('admin.website.media.update', $asset), ['directory' => '../../'], 'directory'],
            'alt text as an array' => ['PUT', route('admin.website.media.update', $asset), ['alt_text' => ['x']], 'alt_text'],
            'a media delete reason as an array' => ['DELETE', route('admin.website.media.destroy', $asset), ['reason' => ['x']], 'reason'],
        ];

        foreach ($cases as $case => [$method, $url, $data, $field]) {
            $this->assertRefusedWith422($case, $method, $url, $data, $field);
        }
    }

    /**
     * A list screen reads typed filters: a hostile query string is a 422, as HTML and as JSON — never a
     * TypeError and a 500. A malformed SEO target is a 404 by design (it only ever comes from the table).
     */
    public function test_hostile_query_strings_on_the_cms_list_screens_are_a_422_never_a_500(): void
    {
        $lists = [
            'admin.website.statistics.index' => [],
            'admin.website.sections.index' => ['placement' => 'home'],
            'admin.website.sections.available' => ['placement' => 'home'],
            'admin.website.menus.index' => [],
            'admin.website.pages.index' => [],
            'admin.website.pages.export' => [],
            'admin.website.cta-blocks.index' => [],
            'admin.website.faqs.index' => [],
            'admin.website.faq-categories.index' => [],
            'admin.website.seo.index' => [],
            'admin.website.seo.export' => [],
            'admin.website.seo.sitemap.history' => [],
            'admin.website.media.index' => [],
        ];

        $probes = [
            'a search array' => ['search' => ['x']],
            'an unknown status' => ['status' => 'bogus'],
            'a page that is not a number' => ['page' => 'abc'],
            'a sideways sort' => ['direction' => 'sideways'],
            'a page id that is not a number' => ['page_id' => 'abc'],
            'a category that is a path' => ['category' => '../../etc'],
        ];

        foreach ($lists as $name => $params) {
            foreach ($probes as $probe => $query) {
                $url = route($name, $params + $query);

                foreach ([false, true] as $json) {
                    $status = $this->actingAs($this->superAdmin)->sendCms('GET', $url, [], $json)->getStatusCode();

                    $this->assertSame(422, $status, sprintf('%s with %s (%s) must answer 422; it answered %d.', $name, $probe, $json ? 'JSON' : 'HTML', $status));
                }
            }
        }

        foreach (['javascript:alert(1)', 'page:999999999', 'page:abc', 'route:no.such.route'] as $target) {
            $this->actingAs($this->superAdmin)->get(route('admin.website.seo.edit', ['target' => $target]))->assertNotFound();
        }

        $this->actingAs($this->superAdmin)->get(route('admin.website.seo.edit', ['target' => ['x']]))->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The contract rows through the forms
    |--------------------------------------------------------------------------
    */

    /**
     * FT-01 through the store route: an unknown type is a 422 and writes nothing; the service refuses it
     * on its own too.
     */
    public function test_unknown_section_type_cannot_be_placed_through_the_store_route(): void
    {
        $url = route('admin.website.sections.store', ['placement' => 'home']);

        foreach (['nope', 'HERO', '../hero', 'rich_content<script>', 'site.sections.hero'] as $key) {
            $this->assertRefusedWith422('section type '.json_encode($key), 'POST', $url, ['section_key' => $key], 'section_key');
        }

        $before = $this->cmsFingerprint();

        try {
            $this->cmsSections()->place('nope', SectionPlacement::Home);
            $this->fail('SectionService::place() accepted an unknown section type.');
        } catch (UnknownSectionTypeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, $this->cmsFingerprint());
    }

    /**
     * FT-05 through the store route: a type outside its placement is a 422; so is a second instance of a
     * unique type (FT-02's HTTP answer).
     */
    public function test_section_type_not_allowed_in_placement_is_rejected_through_the_store_route(): void
    {
        $cases = [
            'a header on the home page' => ['home', 'header'],
            'a footer on the home page' => ['home', 'footer'],
            'a hero in the header slot' => ['global_header', 'hero'],
            'an about section in the footer slot' => ['global_footer', 'about'],
            'a second hero on the home page' => ['home', 'hero'],
            'a second header' => ['global_header', 'header'],
        ];

        foreach ($cases as $case => [$placement, $key]) {
            $this->assertRefusedWith422($case, 'POST', route('admin.website.sections.store', ['placement' => $placement]), ['section_key' => $key], 'section_key');
        }

        // {placement} is bound by the enum: an unknown value is a 404, not a validation error (§7.1).
        $this->actingAs($this->superAdmin)->get(url('/admin/website/sections/nowhere'))->assertNotFound();
        $this->actingAs($this->superAdmin)->post(url('/admin/website/sections/nowhere'), ['section_key' => 'rich_content'], ['Accept' => 'application/json'])->assertNotFound();
    }

    /**
     * FT-14 through the page forms: a duplicate slug, each reserved word the row names, and a slug held by
     * a page in the trash are 422s whose message names the conflict — never a silent `-2` rename.
     */
    public function test_slug_uniqueness_reserved_words_and_trashed_conflicts_through_the_page_forms(): void
    {
        $this->makeCmsPage(true, ['title' => 'About our team', 'slug' => 'about-our-team']);
        $trashed = $this->makeCmsPage(false, ['title' => 'Old summer offer', 'slug' => 'old-summer-offer']);
        $this->cmsPages()->delete($trashed);
        $editable = $this->makeCmsPage();

        $store = route('admin.website.pages.store');
        $update = route('admin.website.pages.update', $editable);

        $message = $this->assertRefusedWith422('a duplicate slug', 'POST', $store, ['title' => 'Another page', 'layout' => 'content', 'slug' => 'about-our-team'], 'slug');
        $this->assertStringContainsString('already used', $message);

        foreach (['admin', 'login', 'student', 'courses', 'sitemap.xml', 'robots.txt'] as $reserved) {
            foreach (['POST' => $store, 'PUT' => $update] as $method => $url) {
                $message = $this->assertRefusedWith422(
                    sprintf('the reserved slug "%s" (%s)', $reserved, $method),
                    $method,
                    $url,
                    ['title' => 'Reserved attempt', 'layout' => 'content', 'slug' => $reserved],
                    'slug',
                );

                $this->assertStringContainsString($reserved, $message, sprintf('The refusal of "%s" must name the conflict.', $reserved));
                $this->assertStringContainsString('reserved', $message, sprintf('The refusal of "%s" must say it is reserved.', $reserved));
            }
        }

        foreach (['POST' => $store, 'PUT' => $update] as $method => $url) {
            $message = $this->assertRefusedWith422('the slug of a trashed page ('.$method.')', $method, $url, ['title' => 'Summer offer again', 'layout' => 'content', 'slug' => 'old-summer-offer'], 'slug');
            $this->assertStringContainsString('trash', $message, 'The refusal must say the slug belongs to a page in the trash.');
        }

        $this->assertFalse(Page::withTrashed()->where('slug', 'like', 'old-summer-offer-%')->exists(), 'A trashed conflict is never silently renamed.');
        $this->assertFalse(Page::withTrashed()->where('slug', 'like', 'about-our-team-%')->exists(), 'A duplicate is never silently renamed.');
    }

    /**
     * FT-18 through the item routes: a ninth hero statistic is a 422, and deleting the last entry of a
     * repeater whose `min` is 1 is a 422 that leaves the entry in place.
     */
    public function test_repeater_min_and_max_are_enforced_through_the_item_routes(): void
    {
        $hero = $this->cmsSection('hero');
        $url = route('admin.website.sections.items.store', $hero);
        $count = static fn (): int => WebsiteSectionItem::query()->where('website_section_id', $hero->getKey())->where('group', 'statistic')->count();

        $this->assertLessThanOrEqual(8, $count());

        for ($n = $count(); $n < 8; $n++) {
            $this->actingAs($this->superAdmin)
                ->sendCms('POST', $url, ['group' => 'statistic', 'item' => ['label' => 'Statistic '.$n, 'value_mode' => 'manual', 'manual_value' => '10']])
                ->assertOk();
        }

        $this->assertSame(8, $count());

        $this->assertRefusedWith422('a ninth hero statistic', 'POST', $url, ['group' => 'statistic', 'item' => ['label' => 'One too many', 'value_mode' => 'manual', 'manual_value' => '1']], 'items.statistic');
        $this->assertSame(8, $count());

        SectionRegistry::register(self::BOUNDED_TYPE, [
            'label' => 'Bounded list (test type)',
            'placements' => [SectionPlacement::Home->value => 900],
            'fields' => [],
            'repeaters' => [
                'highlight' => [
                    'label' => 'Entries',
                    'min' => 1,
                    'max' => 3,
                    'item_label_field' => 'title',
                    'fields' => [
                        'title' => ['label' => 'Title', 'type' => SectionRegistry::TYPE_TEXT, 'required' => true, 'max_chars' => 80],
                    ],
                ],
            ],
        ]);

        $section = $this->cmsSections()->place(self::BOUNDED_TYPE, SectionPlacement::Home);

        $id = $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.website.sections.items.store', $section), ['group' => 'highlight', 'item' => ['title' => 'The only entry']])
            ->assertOk()
            ->json('id');

        $this->assertIsInt($id);

        $this->assertRefusedWith422('deleting the last entry of a repeater with min 1', 'DELETE', route('admin.website.section-items.destroy', ['item' => $id]), [], 'items.highlight');
        $this->assertNotSoftDeleted('website_section_items', ['id' => $id]);
    }

    /**
     * FT-19 through the menu routes: a grandchild (added or dragged there) and re-parenting an item that
     * has children are 422s that change nothing; the database refuses a third level on its own.
     */
    public function test_menu_cannot_exceed_two_levels_through_the_menu_routes(): void
    {
        $menu = $this->cmsMenu();
        $parent = $this->makeMenuItem($menu);
        $child = $this->makeMenuItem($menu, (int) $parent->getKey());
        $other = $this->makeMenuItem($menu);

        $this->assertSame(1, (int) $child->depth);

        $this->assertRefusedWith422('a grandchild through the store route', 'POST', route('admin.website.menus.items.store', $menu), [
            'label' => 'Grandchild',
            'link_type' => MenuItemLinkType::Url->value,
            'url' => 'https://example.com/grandchild',
            'parent_id' => $child->getKey(),
        ], 'parent_id');

        $tree = [];
        $parentIndex = null;

        foreach ($this->reversedMenuTree($menu) as $node) {
            if ($node['id'] === (int) $other->getKey()) {
                continue;
            }

            if ($node['id'] === (int) $parent->getKey()) {
                $node['children'] = [['id' => (int) $child->getKey(), 'children' => [['id' => (int) $other->getKey(), 'children' => []]]]];
                $parentIndex = count($tree);
            }

            $tree[] = $node;
        }

        $this->assertNotNull($parentIndex);
        $this->assertRefusedWith422('a grandchild through the reorder route', 'POST', route('admin.website.menus.reorder', $menu), ['tree' => $tree], 'tree.'.$parentIndex.'.children.0.children');

        $this->assertRefusedWith422('re-parenting an item that has children', 'PUT', route('admin.website.menu-items.update', $parent), [
            'label' => (string) $parent->label,
            'link_type' => MenuItemLinkType::Url->value,
            'url' => (string) $parent->url,
            'parent_id' => $other->getKey(),
        ], 'parent_id');

        $this->assertDatabaseHas('menu_items', ['id' => $parent->getKey(), 'parent_id' => null, 'depth' => 0]);
        $this->assertDatabaseHas('menu_items', ['id' => $child->getKey(), 'parent_id' => $parent->getKey(), 'depth' => 1]);

        try {
            DB::table('menu_items')->insert([
                'menu_id' => $menu->getKey(),
                'parent_id' => null,
                'label' => 'Raw third level',
                'link_type' => MenuItemLinkType::Url->value,
                'url' => 'https://example.com/raw',
                'depth' => 2,
                'sort_order' => 999,
                'visibility' => 'all',
                'is_enabled' => true,
                'open_new_tab' => false,
                'rel_nofollow' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->fail('A menu item with depth 2 was inserted: CHECK chk_mi_depth is missing.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('chk_mi_depth', $exception->getMessage());
        }
    }

    /**
     * FT-20 through the menu routes: an item as its own parent, or as the parent of its own parent, is a
     * 422 and changes nothing.
     */
    public function test_menu_cycle_is_rejected_through_the_menu_routes(): void
    {
        $menu = $this->cmsMenu();
        $parent = $this->makeMenuItem($menu);
        $child = $this->makeMenuItem($menu, (int) $parent->getKey());

        $payload = static fn (MenuItem $item, int $parentId): array => [
            'label' => (string) $item->label,
            'link_type' => MenuItemLinkType::Url->value,
            'url' => (string) $item->url,
            'parent_id' => $parentId,
        ];

        $this->assertRefusedWith422('an item as its own parent', 'PUT', route('admin.website.menu-items.update', $parent), $payload($parent, (int) $parent->getKey()), 'parent_id');
        $this->assertRefusedWith422('a child as its own parent', 'PUT', route('admin.website.menu-items.update', $child), $payload($child, (int) $child->getKey()), 'parent_id');
        $this->assertRefusedWith422('an item under its own child', 'PUT', route('admin.website.menu-items.update', $parent), $payload($parent, (int) $child->getKey()), 'parent_id');

        $this->assertDatabaseHas('menu_items', ['id' => $parent->getKey(), 'parent_id' => null, 'depth' => 0]);
        $this->assertDatabaseHas('menu_items', ['id' => $child->getKey(), 'parent_id' => $parent->getKey(), 'depth' => 1]);
    }

    /**
     * FT-33 through the upload route: a PHP script renamed `.jpg`, a text file renamed `.png`, markup
     * behind an image extension or magic number, an SVG (with its reason) and a file above
     * `security.max_upload_mb` are 422s that store nothing; a genuine JPEG is accepted.
     */
    public function test_upload_validates_by_content_not_extension_through_the_media_route(): void
    {
        $uploader = $this->createUserWithPermissions(['website_media.view_any', 'website_media.view', 'website_media.upload']);
        $url = route('admin.website.media.store');

        $refused = [
            'a PHP script renamed .jpg' => UploadedFile::fake()->createWithContent('holiday.jpg', '<?php system($_GET["c"]); ?>'),
            'a PHP payload behind a JPEG magic number' => UploadedFile::fake()->createWithContent('selfie.jpg', "\xFF\xD8\xFF\xE0".'<?php echo "owned"; ?>'),
            'a text file renamed .png' => UploadedFile::fake()->createWithContent('notes.png', 'Just some notes, not an image at all.'),
            'HTML renamed .gif' => UploadedFile::fake()->createWithContent('banner.gif', '<html><body><script>alert(1)</script></body></html>'),
            'an SVG' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'an SVG renamed .png' => UploadedFile::fake()->createWithContent('logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'),
            'a 50 MB file' => UploadedFile::fake()->create('huge.jpg', 51200),
        ];

        foreach ($refused as $case => $file) {
            $response = $this->actingAs($uploader)->post($url, ['file' => $file], ['Accept' => 'application/json']);

            $this->assertSame(422, $response->getStatusCode(), sprintf('%s must be refused with 422; it answered %d: %s', $case, $response->getStatusCode(), Str::limit((string) $response->getContent(), 300)));
            $response->assertJsonValidationErrors('file');

            if (str_contains($case, 'SVG')) {
                $this->assertStringContainsString('SVG', (string) $response->json('errors.file.0'), 'The SVG refusal must state its reason.');
            }

            $this->assertSame(0, MediaAsset::withTrashed()->count(), $case.' created a media row.');
            $this->assertSame([], Storage::disk('public')->allFiles(), $case.' left a file on the public disk.');
        }

        $response = $this->actingAs($uploader)
            ->post($url, ['file' => UploadedFile::fake()->image('photo.jpg', 800, 600), 'alt_text' => 'A genuine photograph'], ['Accept' => 'application/json'])
            ->assertOk();

        $asset = MediaAsset::query()->findOrFail($response->json('asset.id'));

        $this->assertSame('image/jpeg', (string) $asset->mime_type);
        $this->assertSame('photo.jpg', (string) $asset->original_name);
        $this->assertNotSame('photo.jpg', (string) $asset->filename, 'The user\'s filename never becomes the stored name.');
        Storage::disk('public')->assertExists($asset->directory.'/'.$asset->filename);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Send one request as the Super Admin and assert: 422, the named field carries an error, and no CMS row
     * or audit row changed. Returns the field's first message.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRefusedWith422(string $case, string $method, string $url, array $data, string $field): string
    {
        $before = $this->cmsFingerprint();

        $response = $this->actingAs($this->superAdmin)->sendCms($method, $url, $data);

        $this->assertSame(
            422,
            $response->getStatusCode(),
            sprintf('%s (%s %s) must be refused with 422; it answered %d: %s', $case, $method, $url, $response->getStatusCode(), Str::limit((string) $response->getContent(), 400)),
        );

        $errors = (array) $response->json('errors');

        $this->assertArrayHasKey(
            $field,
            $errors,
            sprintf('%s must name the field [%s]; the errors were: %s', $case, $field, json_encode(array_keys($errors))),
        );

        $this->assertSame($before, $this->cmsFingerprint(), $case.' wrote something although it was refused.');

        return (string) (((array) $errors[$field])[0] ?? '');
    }
}
