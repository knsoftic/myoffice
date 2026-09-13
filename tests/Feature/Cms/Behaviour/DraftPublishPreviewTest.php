<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\RevisionEvent;
use App\Enums\Cms\SectionPlacement;
use App\Models\Activity;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\Menu;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.2 — draft, publish, preview: the heart of the phase (FT-06, FT-07, FT-08, FT-09, FT-10,
 * FT-11, FT-13, FT-21, FT-22, FT-23).
 *
 * D22 in behaviour: the public site renders the `published_content` snapshot and nothing else (INV-1);
 * "unpublished changes" is a generated fact, not a flag (INV-4); a snapshot folds in enabled items,
 * media, the resolved CTA and the resolved menu tree at publish time; unpublish and revert never destroy
 * the live copy; an incomplete section is never half-published; and a preview is visible only to the
 * permission (or a valid signature), is never cached and never indexed (INV-9).
 *
 * Data isolation (§9) is asserted in FT-21: a signed-in collaborator, student, teacher or client is an
 * ordinary visitor — the draft is a 404 to them and `?preview=1` shows them the live page.
 */
final class DraftPublishPreviewTest extends TestCase
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
        $this->forgetTestSectionTypes();

        parent::tearDown();
    }

    /** FT-06 */
    public function test_draft_edit_is_invisible_to_the_public_until_published(): void
    {
        $this->publishHeroHeading('FT06 Heading Alpha');

        $this->sections()->saveDraft($this->seededSection('hero'), ['heading' => 'FT06 Heading Bravo']);

        $this->get('/')
            ->assertOk()
            ->assertSee('FT06 Heading Alpha')
            ->assertDontSee('FT06 Heading Bravo');

        // A second anonymous request (now possibly from the page cache) still shows only the live copy.
        $this->get('/')
            ->assertOk()
            ->assertSee('FT06 Heading Alpha')
            ->assertDontSee('FT06 Heading Bravo');

        $this->publisher()->publish($this->seededSection('hero'));

        $this->get('/')
            ->assertOk()
            ->assertSee('FT06 Heading Bravo')
            ->assertDontSee('FT06 Heading Alpha');
    }

    /** FT-07 */
    public function test_unpublished_and_disabled_sections_are_absent_from_the_public_html(): void
    {
        $first = $this->placeRichContent('FT07 First Block');
        $second = $this->placeRichContent('FT07 Second Block');
        $third = $this->placeRichContent('FT07 Third Block');

        $this->assertInOrder($this->get('/')->assertOk()->getContent(), ['FT07 First Block', 'FT07 Second Block', 'FT07 Third Block']);

        // (a) status = draft
        $this->publisher()->unpublish($first, 'FT-07: taking the first block down');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('FT07 First Block', $html, 'A draft section must not render.');
        $this->assertInOrder($html, ['FT07 Second Block', 'FT07 Third Block']);

        $this->publisher()->publish($first);
        $this->assertInOrder($this->get('/')->assertOk()->getContent(), ['FT07 First Block', 'FT07 Second Block', 'FT07 Third Block']);

        // (b) is_enabled = false
        $this->sections()->disable($second, 'FT-07: hidden for a day');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('FT07 Second Block', $html, 'A disabled section must not render.');
        $this->assertInOrder($html, ['FT07 First Block', 'FT07 Third Block']);

        $this->sections()->enable($second);

        // (c) published_content = null, with the status still `published` (a hand-edited row).
        DB::table('website_sections')->where('id', $third->getKey())->update(['published_content' => null]);
        $this->bumpPublicCache('FT-07 raw edit');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('FT07 Third Block', $html, 'A section with no snapshot must not render.');
        $this->assertInOrder($html, ['FT07 First Block', 'FT07 Second Block']);
        $this->assertSame(ContentStatus::Published->value, $this->sectionRow($third)->status);
    }

    /** FT-08 */
    public function test_has_unpublished_changes_is_derived_not_set(): void
    {
        $hero = $this->publishHeroHeading('FT08 Live Heading');
        $this->assertSame(0, (int) $this->sectionRow($hero)->has_unpublished_changes, 'Right after publish the draft is the live copy.');

        $hero = $this->sections()->saveDraft($hero, ['heading' => 'FT08 Changed Heading']);
        $this->assertSame(1, (int) $this->sectionRow($hero)->has_unpublished_changes, 'A real draft change is an unpublished change.');

        $hero = $this->publisher()->publish($hero);
        $this->assertSame(0, (int) $this->sectionRow($hero)->has_unpublished_changes);

        $hash = $this->sectionRow($hero)->content_hash;
        $revisions = DB::table('cms_revisions')
            ->where('revisionable_type', $hero->getMorphClass())
            ->where('revisionable_id', $hero->getKey())
            ->count();

        $hero = $this->sections()->saveDraft($hero, ['heading' => 'FT08 Changed Heading']);

        $this->assertSame(0, (int) $this->sectionRow($hero)->has_unpublished_changes, 'Saving identical data leaves the hash equal, so nothing is unpublished.');
        $this->assertSame($hash, $this->sectionRow($hero)->content_hash);
        $this->assertSame($revisions, DB::table('cms_revisions')->where('revisionable_type', $hero->getMorphClass())->where('revisionable_id', $hero->getKey())->count(), 'An identical save writes no revision.');

        // The column is generated: a raw write is refused or ignored, never stored.
        try {
            DB::update('UPDATE `website_sections` SET `has_unpublished_changes` = 1 WHERE `id` = ?', [$hero->getKey()]);
        } catch (QueryException) {
            // MariaDB refuses a value for a generated column — equally a pass.
        }

        $this->assertSame(0, (int) $this->sectionRow($hero)->has_unpublished_changes, 'A raw UPDATE cannot make has_unpublished_changes lie.');

        $generated = DB::selectOne(
            'SELECT `EXTRA` AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['website_sections', 'has_unpublished_changes']
        );

        $this->assertNotNull($generated);
        $this->assertMatchesRegularExpression('/(STORED|PERSISTENT) GENERATED/i', (string) $generated->extra, 'has_unpublished_changes must be a stored generated column.');
    }

    /** FT-09 */
    public function test_publish_snapshot_contains_items_media_and_resolved_references(): void
    {
        $hero = $this->seededSection('hero');

        foreach (WebsiteSectionItem::query()->where('website_section_id', $hero->getKey())->get() as $seeded) {
            $this->sections()->deleteItem($seeded);
        }

        $alpha = $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT09 Alpha', 'value_mode' => 'manual', 'manual_value' => '100']);
        $bravo = $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT09 Bravo', 'value_mode' => 'manual', 'manual_value' => '200', 'is_enabled' => false]);
        $charlie = $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT09 Charlie', 'value_mode' => 'manual', 'manual_value' => '300']);

        $this->sections()->reorderItems($hero, 'statistic', [(int) $charlie->getKey(), (int) $alpha->getKey(), (int) $bravo->getKey()]);

        $asset = $this->makeImageAsset(['alt_text' => 'FT09 hero illustration']);

        $hero = $this->sections()->saveDraft($hero, ['heading' => 'FT09 Hero'], ['hero_image' => (int) $asset->getKey()]);
        $hero = $this->publisher()->publish($hero);

        $snapshot = $this->snapshotOf($hero);
        $this->assertIsArray($snapshot);

        // Enabled items only, in sort_order.
        $labels = array_map(static fn (array $item): ?string => $item['content']['label'] ?? null, $snapshot['items']['statistic'] ?? []);
        $this->assertSame(['FT09 Charlie', 'FT09 Alpha'], $labels, 'The snapshot holds the enabled items in sort_order; the disabled one is excluded.');
        $this->assertSame('300.00', $snapshot['items']['statistic'][0]['value'] ?? null, 'A manual statistic is frozen as a decimal string.');

        // Media: URL and srcsets.
        $image = $snapshot['media']['hero_image'] ?? null;
        $this->assertIsArray($image);
        $this->assertStringContainsString((string) $asset->filename, (string) $image['url']);
        $this->assertStringContainsString('640w', (string) $image['srcset']);
        $this->assertStringContainsString('.webp 640w', (string) $image['webp_srcset']);
        $this->assertSame('FT09 hero illustration', $image['alt']);

        // The resolved CTA block, not an id.
        $cta = $this->seededSection('cta');
        $block = DB::table('cta_blocks')->where('id', $this->sectionRow($cta)->cta_block_id)->first();
        $this->assertNotNull($block);
        $this->assertSame($block->heading, $this->snapshotOf($cta)['cta']['heading'] ?? null, 'The CTA snapshot carries the block content itself.');

        // The resolved menu tree: enabled items with computed URLs.
        $header = $this->seededSection('header', SectionPlacement::GlobalHeader);
        /** @var Menu $menu */
        $menu = Menu::query()->findOrFail((int) $this->sectionRow($header)->menu_id);

        $this->menus()->storeItem($menu, ['label' => 'FT09 Enabled Link', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/ft09-enabled']);
        $this->menus()->storeItem($menu, ['label' => 'FT09 Disabled Link', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/ft09-disabled', 'is_enabled' => false]);

        $header = $this->publisher()->publish($header);
        $tree = $this->snapshotOf($header)['menus']['menu_ref']['items'] ?? [];
        $byLabel = array_column($tree, 'url', 'label');

        $this->assertSame('/ft09-enabled', $byLabel['FT09 Enabled Link'] ?? null, 'An enabled menu item is frozen with its resolved URL.');
        $this->assertArrayNotHasKey('FT09 Disabled Link', $byLabel, 'A disabled menu item never reaches the snapshot.');

        // A later change to a disabled item does not change the live snapshot.
        $published = $this->sectionRow($hero)->published_content;

        $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT09 Bravo Renamed', 'manual_value' => '999'], $bravo);

        $this->assertSame($published, $this->sectionRow($hero)->published_content, 'Editing a disabled item never touches published_content.');
    }

    /** FT-10 */
    public function test_unpublish_keeps_the_snapshot_and_requires_a_reason(): void
    {
        $section = $this->placeRichContent('FT10 Seasonal Block');

        $this->get('/')->assertOk()->assertSee('FT10 Seasonal Block');

        $this->assertThrows(
            fn () => $this->publisher()->unpublish($section, '   '),
            InvalidSectionContentException::class,
        );

        $this->actingAs($this->createSuperAdmin())
            ->postJson(route('admin.website.sections.unpublish', $section), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(ContentStatus::Published->value, $this->sectionRow($section)->status, 'A refused unpublish leaves the section live.');

        $before = Activity::query()->where('module', 'website_sections')->where('event', 'unpublished')->count();

        $this->publisher()->unpublish($section, 'FT-10 campaign ended early');

        $row = $this->sectionRow($section);
        $this->assertSame(ContentStatus::Draft->value, $row->status);
        $this->assertNotNull($row->published_content, 'Unpublishing keeps the snapshot, so a re-publish is lossless.');
        $this->assertSame('FT-10 campaign ended early', $row->unpublished_reason);

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee('FT10 Seasonal Block');

        $this->assertSame($before + 1, Activity::query()->where('module', 'website_sections')->where('event', 'unpublished')->count());

        $activity = Activity::query()->where('module', 'website_sections')->where('event', 'unpublished')->latest('id')->firstOrFail();
        $this->assertSame('FT-10 campaign ended early', $activity->reason);
        $this->assertSame((int) $section->getKey(), (int) $activity->subject_id);
    }

    /** FT-11 */
    public function test_revert_restores_the_draft_not_the_live_version(): void
    {
        $section = $this->sections()->place('rich_content', SectionPlacement::Home);

        /** @var CmsRevision $original */
        $original = CmsRevision::query()
            ->where('revisionable_type', $section->getMorphClass())
            ->where('revisionable_id', $section->getKey())
            ->where('event', RevisionEvent::Created->value)
            ->firstOrFail();

        $section = $this->sections()->saveDraft($section, ['heading' => 'FT11 Live Heading']);
        $section = $this->publisher()->publish($section);
        $section = $this->sections()->saveDraft($section, ['heading' => 'FT11 Second Draft']);

        $published = $this->sectionRow($section)->published_content;
        $publishedHash = $this->sectionRow($section)->published_hash;

        $this->assertThrows(
            fn () => $this->publisher()->revert($section, $original, ''),
            InvalidSectionContentException::class,
        );

        $this->publisher()->revert($section, $original, 'FT-11 back to the original placement');

        $row = $this->sectionRow($section);
        $content = json_decode((string) $row->content, true);

        $this->assertNotSame('FT11 Second Draft', $content['heading'] ?? null, 'Reverting changes the draft.');
        $this->assertNull($content['heading'] ?? null, 'The draft is the revision\'s content.');
        $this->assertSame($published, $row->published_content, 'Reverting never touches the live copy.');
        $this->assertSame($publishedHash, $row->published_hash);

        $this->get('/')
            ->assertOk()
            ->assertSee('FT11 Live Heading')
            ->assertDontSee('FT11 Second Draft');

        $reverted = DB::table('cms_revisions')
            ->where('revisionable_type', $section->getMorphClass())
            ->where('revisionable_id', $section->getKey())
            ->where('event', RevisionEvent::Reverted->value)
            ->get();

        $this->assertCount(1, $reverted, 'A reverted revision is appended.');
        $this->assertSame('FT-11 back to the original placement', $reverted->first()->reason);
    }

    /** FT-13 */
    public function test_publish_refuses_incomplete_content(): void
    {
        $admin = $this->createSuperAdmin();

        // (1) A required field is empty.
        $hero = $this->publishHeroHeading('FT13 Live Heading');
        $liveHash = $this->sectionRow($hero)->published_hash;

        $hero = $this->sections()->saveDraft($hero, ['heading' => null]);

        $this->assertThrows(fn () => $this->publisher()->publish($hero), InvalidSectionContentException::class);

        $this->actingAs($admin)
            ->postJson(route('admin.website.sections.publish', $hero))
            ->assertStatus(422);

        $this->assertSame($liveHash, $this->sectionRow($hero)->published_hash, 'An empty required field leaves the live hash unchanged.');

        // (3) A placed image has no alt text.
        $hero = $this->sections()->saveDraft($hero, ['heading' => 'FT13 Complete Heading'], [
            'hero_image' => (int) $this->makeImageAsset(['alt_text' => null])->getKey(),
        ]);

        $this->assertThrows(fn () => $this->publisher()->publish($hero), InvalidSectionContentException::class);

        $this->actingAs($admin)
            ->postJson(route('admin.website.sections.publish', $hero))
            ->assertStatus(422);

        $this->assertSame($liveHash, $this->sectionRow($hero)->published_hash, 'An image without alt text leaves the live hash unchanged.');

        // (2) A required media role is empty (a later phase's type declares one through the registry seam).
        $this->registerTestSectionType('ft13_required_media', [
            'label' => 'FT-13 required media',
            'group' => 'content',
            'placements' => [SectionPlacement::Home->value => 900],
            'unique' => false,
            'required' => false,
            'fields' => [
                'heading' => ['label' => 'Heading', 'type' => 'text', 'required' => true, 'default' => null],
            ],
            'repeaters' => [],
            'media' => [
                'image_1' => ['label' => 'Main image', 'profile' => ImageProfile::Card, 'required' => true],
            ],
        ]);

        $section = $this->sections()->place('ft13_required_media', SectionPlacement::Home);
        $section = $this->sections()->saveDraft($section, ['heading' => 'FT13 Needs An Image']);

        $this->assertThrows(fn () => $this->publisher()->publish($section), InvalidSectionContentException::class);

        $this->actingAs($admin)
            ->postJson(route('admin.website.sections.publish', $section))
            ->assertStatus(422);

        $row = $this->sectionRow($section);
        $this->assertNull($row->published_hash, 'A section missing a required image is never published.');
        $this->assertSame(ContentStatus::Draft->value, $row->status);
    }

    /** FT-21 */
    public function test_preview_shows_draft_content_to_an_authorised_user(): void
    {
        $hero = $this->publishHeroHeading('FT21 Live Alpha');
        $hero = $this->sections()->saveDraft($hero, ['heading' => 'FT21 Draft Bravo']);

        $viewer = $this->createUserWithPermissions(['website_sections.view']);

        $this->actingAs($viewer)
            ->get(route('site.preview.section', $hero))
            ->assertOk()
            ->assertSee('FT21 Draft Bravo');

        $this->becomeGuest();

        $this->get('/')
            ->assertOk()
            ->assertSee('FT21 Live Alpha')
            ->assertDontSee('FT21 Draft Bravo');

        $this->get(route('site.preview.section', $hero))
            ->assertNotFound()
            ->assertDontSee('FT21 Draft Bravo');

        foreach (['Collaborator', 'Student', 'Teacher', 'Client'] as $role) {
            $user = $this->createUserWithRole($role);

            $this->actingAs($user)
                ->get(route('site.preview.section', $hero))
                ->assertNotFound()
                ->assertDontSee('FT21 Draft Bravo');

            // A signed-in portal user is an ordinary visitor: `?preview=1` shows them the live page.
            $this->actingAs($user)
                ->get('/?preview=1')
                ->assertOk()
                ->assertSee('FT21 Live Alpha')
                ->assertDontSee('FT21 Draft Bravo');

            $this->becomeGuest();
        }
    }

    /** FT-22 */
    public function test_preview_is_never_cached_and_never_indexed(): void
    {
        $hero = $this->sections()->saveDraft($this->seededSection('hero'), ['heading' => 'FT22 Draft Heading']);
        $viewer = $this->createUserWithPermissions(['website_sections.view']);

        $keys = $this->pageCacheKeys();

        $response = $this->actingAs($viewer)
            ->get(route('site.preview.section', $hero))
            ->assertOk()
            ->assertSee('FT22 Draft Heading');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertMatchesRegularExpression('~<meta\s+name="robots"\s+content="noindex, nofollow"~i', (string) $response->getContent());
        $this->assertSame($keys, $this->pageCacheKeys(), 'A preview never creates a page cache entry.');

        // `?preview=1` on a cacheable public URL short-circuits the cache too, even for a guest.
        $this->becomeGuest();

        $flagged = $this->get('/?preview=1')->assertOk();

        $this->assertStringContainsString('no-store', (string) $flagged->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $flagged->headers->get('X-Robots-Tag'));
        $this->assertSame($keys, $this->pageCacheKeys(), 'A preview-shaped request is never stored.');
    }

    /** FT-23 */
    public function test_signed_preview_link_works_and_expires(): void
    {
        $page = $this->makePage('ft23-preview', 'FT23 Draft Page', '<p>FT23 draft body text</p>', publish: false);

        $url = (string) $this->actingAs($this->createSuperAdmin())
            ->getJson(route('admin.website.pages.preview-link', $page))
            ->assertOk()
            ->json('url');

        $this->assertStringContainsString('signature=', $url);

        $this->becomeGuest();

        $this->get($url)
            ->assertOk()
            ->assertSee('FT23 draft body text');

        $this->get('/ft23-preview')->assertNotFound();

        $tampered = substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a');
        $this->get($tampered)->assertForbidden();

        $this->post($url)->assertStatus(405);

        $ttl = setting('website.preview_ttl_minutes', 120);
        $ttl = is_numeric($ttl) ? (int) $ttl : 120;

        $this->travel($ttl + 1)->minutes();

        $this->get($url)->assertForbidden();
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertInOrder(string $haystack, array $needles): void
    {
        $offset = -1;

        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle);

            $this->assertNotFalse($position, sprintf('"%s" is missing from the page.', $needle));
            $this->assertGreaterThan($offset, $position, sprintf('"%s" is out of order.', $needle));

            $offset = (int) $position;
        }
    }
}
