<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Page;
use App\Services\Cms\CtaBlockService;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\PageService;
use Database\Seeders\WebsiteCmsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;
use Throwable;

/**
 * phase-03 §11.5 — pages and slugs (FT-14, FT-17, FT-12, FT-32).
 *
 * A slug is a public address: it is unique against every row including the trash (a trashed page keeps
 * its address, R-5), never a reserved first segment, and never silently renamed. The four policy pages are
 * editable but never deletable — for a Super Admin too (M-7) — and a seeder re-run never overwrites what
 * an administrator wrote. A referenced entity cannot leave a dangling id in JSON (INV-3), and a scheduled
 * page promotes itself with one cache bump for the batch (§10.4).
 */
final class PageLifecycleTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The notification §10.3 names for a scheduled page that went live. */
    private const SCHEDULED_PAGE_PUBLISHED = 'App\\Notifications\\Cms\\ScheduledPagePublished';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /** FT-14 */
    public function test_slug_uniqueness_reserved_words_and_trashed_conflicts(): void
    {
        $admin = $this->createSuperAdmin();

        $this->makePage('ft14-taken', 'FT14 Taken Page');
        $pages = DB::table('pages')->count();

        // A duplicate slug.
        $this->assertThrows(
            fn () => $this->pages()->create(['title' => 'FT14 Duplicate', 'slug' => 'ft14-taken']),
            ContentActionNotAllowedException::class,
        );

        $this->actingAs($admin)
            ->postJson(route('admin.website.pages.store'), ['title' => 'FT14 Duplicate', 'slug' => 'ft14-taken', 'layout' => PageLayout::Content->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // Reserved first segments.
        foreach (['admin', 'login', 'student', 'courses', 'sitemap.xml', 'robots.txt'] as $slug) {
            $response = $this->actingAs($admin)
                ->postJson(route('admin.website.pages.store'), ['title' => 'FT14 '.$slug, 'slug' => $slug, 'layout' => PageLayout::Content->value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('slug');

            $refusal = null;

            try {
                $this->pages()->create(['title' => 'FT14 '.$slug, 'slug' => $slug]);
            } catch (ContentActionNotAllowedException|InvalidSectionContentException $exception) {
                $refusal = $exception;
            }

            $this->assertInstanceOf(Throwable::class, $refusal, sprintf('"/%s" must be refused by PageService.', $slug));

            if (preg_match(PageService::SLUG_PATTERN, $slug) === 1) {
                // A slug a page could otherwise hold: the refusal names the conflict.
                $message = (string) $response->json('errors.slug.0');

                $this->assertStringContainsString('reserved', $message, sprintf('The refusal of "/%s" must say it is reserved.', $slug));
                $this->assertStringContainsString($slug, $message);
                $this->assertStringContainsString('reserved', $refusal->getMessage());
            }
        }

        // A slug still held by a page in the trash: refused by name, never a silent -2.
        $trashed = $this->makePage('ft14-in-trash', 'FT14 Trashed Page');
        $this->pages()->delete($trashed);
        $pages = DB::table('pages')->count();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.website.pages.store'), ['title' => 'FT14 Reuse', 'slug' => 'ft14-in-trash', 'layout' => PageLayout::Content->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->assertStringContainsString('trash', (string) $response->json('errors.slug.0'), 'The refusal must say the address belongs to a page in the trash.');

        $this->assertThrows(
            fn () => $this->pages()->create(['title' => 'FT14 Reuse', 'slug' => 'ft14-in-trash']),
            static fn (ContentActionNotAllowedException $exception): bool => str_contains($exception->getMessage(), 'trash'),
        );

        $this->assertSame($pages, DB::table('pages')->count(), 'No page was created by any refused slug.');
        $this->assertFalse(DB::table('pages')->where('slug', 'like', 'ft14-in-trash-%')->exists(), 'A trashed conflict is never silently renamed.');
    }

    /** FT-17 */
    public function test_system_pages_cannot_be_deleted(): void
    {
        /** @var Page $privacy */
        $privacy = Page::query()->where('slug', 'privacy-policy')->firstOrFail();
        $this->assertTrue($privacy->isSystem());

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->deleteJson(route('admin.website.pages.destroy', $privacy))
            ->assertForbidden();

        $this->assertThrows(fn () => $this->pages()->delete($privacy), ContentActionNotAllowedException::class);

        $editor = $this->createUserWithPermissions(['pages.view_any', 'pages.view', 'pages.delete']);

        $this->actingAs($editor)
            ->deleteJson(route('admin.website.pages.destroy', $privacy))
            ->assertForbidden();

        $this->assertNull(DB::table('pages')->where('id', $privacy->getKey())->value('deleted_at'), 'The system page survives every delete attempt.');

        // Its content IS editable.
        $this->actingAs($admin)
            ->putJson(route('admin.website.pages.update', $privacy), ['content' => '<p>FT17 Our real privacy policy.</p>'])
            ->assertOk();

        $this->publisher()->publish($privacy->fresh());

        $this->becomeGuest();
        $this->get('/privacy-policy')->assertOk()->assertSee('FT17 Our real privacy policy.');

        // Re-running the seeder does not overwrite the edited content.
        $this->seed(WebsiteCmsSeeder::class);

        $row = DB::table('pages')->where('slug', 'privacy-policy')->first();
        $this->assertStringContainsString('FT17 Our real privacy policy.', (string) $row->content);
        $this->assertStringContainsString('FT17 Our real privacy policy.', (string) $row->published_content);
        $this->assertSame(1, DB::table('pages')->where('slug', 'privacy-policy')->count());
    }

    /** FT-12 */
    public function test_deleting_a_referenced_entity_cannot_orphan_json(): void
    {
        $admin = $this->createSuperAdmin();
        $now = Carbon::now();

        $blockId = (int) DB::table('cta_blocks')->insertGetId([
            'key' => 'ft12_cta',
            'name' => 'FT12 CTA',
            'variant' => CtaVariant::Banner->value,
            'heading' => 'FT12 CTA Heading',
            'primary_label' => 'FT12 Go',
            'primary_url' => '/ft12-go',
            'primary_style' => ButtonStyle::Primary->value,
            'secondary_style' => ButtonStyle::Outline->value,
            'status' => ContentStatus::Published->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $section = $this->sections()->place('cta', SectionPlacement::Home);
        $section = $this->sections()->saveDraft($section, ['cta_ref' => $blockId]);
        $section = $this->publisher()->publish($section);

        $this->assertSame($blockId, (int) $this->sectionRow($section)->cta_block_id, 'The reference is a real FK column (INV-3).');
        $this->assertArrayNotHasKey('cta_ref', (array) json_decode((string) $this->sectionRow($section)->content, true), 'No foreign key lives in the content JSON.');

        $this->get('/')->assertOk()->assertSee('FT12 CTA Heading');

        // The library refuses to delete a block that is still used.
        $this->assertThrows(
            fn () => app(CtaBlockService::class)->delete(CtaBlock::query()->findOrFail($blockId)),
            ContentActionNotAllowedException::class,
        );

        // A hard delete below the application: the FK nulls the reference instead of orphaning it.
        DB::table('cta_blocks')->where('id', $blockId)->delete();

        $this->assertNull($this->sectionRow($section)->cta_block_id, 'cta_block_id is nulled on delete.');

        $this->bumpPublicCache('FT-12 raw delete');
        $this->get('/')->assertOk()->assertDontSee('FT12 CTA Heading');

        $this->assertNull($this->sections()->draftPayload($section->fresh())['cta'], 'The section now resolves to no CTA.');

        $this->actingAs($admin)
            ->get(route('site.preview.section', $section))
            ->assertOk()
            ->assertDontSee('FT12 CTA Heading');

        // A media asset in use: the database restricts the delete, and so does the library.
        $asset = $this->makeImageAsset(['alt_text' => 'FT12 image']);
        $rich = $this->sections()->place('rich_content', SectionPlacement::Home);
        $this->sections()->saveDraft($rich, ['heading' => 'FT12 Rich'], ['image_1' => (int) $asset->getKey()]);

        $this->assertThrows(
            fn () => DB::table('media_assets')->where('id', $asset->getKey())->delete(),
            QueryException::class,
        );

        $this->assertTrue(DB::table('media_assets')->where('id', $asset->getKey())->exists(), 'restrictOnDelete keeps the asset.');

        $librarian = $this->createUserWithPermissions(['website_media.view_any', 'website_media.view', 'website_media.delete']);

        $this->actingAs($librarian)
            ->deleteJson(route('admin.website.media.destroy', $asset))
            ->assertForbidden();

        $this->assertNull(DB::table('media_assets')->where('id', $asset->getKey())->value('deleted_at'), 'The policy refuses a delete while the asset is used.');
    }

    /** FT-32 */
    public function test_scheduled_page_publishes_itself(): void
    {
        $start = Carbon::parse('2026-09-13 10:00:00', 'UTC');
        $this->travelTo($start);

        $due = $this->makePage('ft32-due', 'FT32 Due Page', '<p>FT32 due body</p>', publish: false);
        $alsoDue = $this->makePage('ft32-also-due', 'FT32 Also Due Page', '<p>FT32 also due body</p>', publish: false);
        $future = $this->makePage('ft32-future', 'FT32 Future Page', '<p>FT32 future body</p>', publish: false);

        $this->publisher()->schedule($due, $start->copy()->addMinutes(10));
        $this->publisher()->schedule($alsoDue, $start->copy()->addMinutes(20));
        $this->publisher()->schedule($future, $start->copy()->addDays(3));

        $this->assertSame(ContentStatus::Scheduled, $this->statusOf($due));
        $this->get('/ft32-due')->assertNotFound();

        $this->travelTo($start->copy()->addHour());

        $version = $this->cacheVersion()->version();

        $this->artisan('cms:publish-scheduled')->assertSuccessful();

        $this->assertSame(ContentStatus::Published, $this->statusOf($due));
        $this->assertSame(ContentStatus::Published, $this->statusOf($alsoDue));
        $this->assertSame(ContentStatus::Scheduled, $this->statusOf($future), 'A future publish date stays scheduled.');

        $this->assertSame(
            $start->copy()->addMinutes(10)->toDateTimeString(),
            Carbon::parse((string) DB::table('pages')->where('id', $due->getKey())->value('published_at'))->toDateTimeString(),
            'published_at keeps the scheduled moment.',
        );

        $this->assertSame($version + 1, $this->cacheVersion()->version(), 'The cache is bumped once for the whole batch.');

        $this->get('/ft32-due')->assertOk()->assertSee('FT32 due body');
        $this->get('/ft32-also-due')->assertOk()->assertSee('FT32 also due body');
        $this->get('/ft32-future')->assertNotFound();

        // Running it again promotes nothing and bumps nothing.
        $this->artisan('cms:publish-scheduled')->assertSuccessful();
        $this->assertSame($version + 1, $this->cacheVersion()->version());
    }

    /** FT-32 (§10.3 `ScheduledPagePublished`) */
    public function test_scheduled_page_publishes_itself_and_notifies_the_author_and_publishers(): void
    {
        Notification::fake();

        $start = Carbon::parse('2026-09-13 10:00:00', 'UTC');
        $this->travelTo($start);

        $author = $this->createUserWithPermissions(['pages.view_any', 'pages.view', 'pages.create', 'pages.edit']);
        $publisher = $this->createUserWithPermissions(['pages.view_any', 'pages.view', 'pages.change_status']);

        $this->actingAs($author);
        $page = $this->makePage('ft32-notify', 'FT32 Notify Page', '<p>FT32 notify body</p>', publish: false);
        $this->assertSame((int) $author->getKey(), (int) DB::table('pages')->where('id', $page->getKey())->value('created_by'));

        $this->publisher()->schedule($page, $start->copy()->addMinutes(5));

        $this->becomeGuest();
        $this->travelTo($start->copy()->addMinutes(30));

        $this->artisan('cms:publish-scheduled')->assertSuccessful();

        $this->assertSame(ContentStatus::Published, $this->statusOf($page));

        Notification::assertSentTo($author, self::SCHEDULED_PAGE_PUBLISHED);
        Notification::assertSentTo($publisher, self::SCHEDULED_PAGE_PUBLISHED);
    }
}
