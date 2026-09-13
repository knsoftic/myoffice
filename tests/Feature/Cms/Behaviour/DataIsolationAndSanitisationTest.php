<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\FaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §9 (data isolation) and FT-36 (rich text sanitised on write and on render, INV-13).
 *
 * §9 asks for every scoping rule to be a query scope or a policy check, with a test asserting both the
 * HTTP status and the absence of the forbidden content from the body:
 *
 *   · an anonymous visitor sees only enabled + published sections with a snapshot, published pages (a
 *     draft page is a 404, never a 403), enabled menu items, published FAQs and CTA blocks — and never a
 *     draft body, an unpublished reason or a revision;
 *   · a student, teacher, client or collaborator is an ordinary visitor on the public site and is refused
 *     every admin CMS route, reads included, with nothing written.
 *
 * FT-36: the database is not a trust boundary, so rich text is sanitised when it is written and again when
 * it is rendered.
 */
final class DataIsolationAndSanitisationTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const HOSTILE = '<p>FT36 Safe paragraph</p>'
        .'<script>alert("ft36-script-body")</script>'
        .'<img src="https://cdn.example.test/ft36.png" onerror="alert(\'ft36-onerror\')" alt="FT36 image">'
        .'<a href="javascript:alert(\'ft36-javascript\')">FT36 bad link</a>'
        .'<iframe src="https://evil.test/ft36-frame"></iframe>'
        .'<iframe src="https://www.youtube.com/embed/ft36video"></iframe>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /** FT-36 */
    public function test_rich_text_is_sanitized_on_write_and_on_render(): void
    {
        // Section rich text, on write.
        $section = $this->sections()->place('rich_content', SectionPlacement::Home);
        $section = $this->sections()->saveDraft($section, ['heading' => 'FT36 Heading', 'body' => self::HOSTILE]);

        $this->assertSanitised((string) (json_decode((string) $this->sectionRow($section)->content, true)['body'] ?? ''));

        // Page body, on write.
        $page = $this->pages()->create(['title' => 'FT36 Page', 'slug' => 'ft36-page', 'content' => self::HOSTILE]);
        $this->assertSanitised((string) DB::table('pages')->where('id', $page->getKey())->value('content'));

        // FAQ answer, on write.
        $categoryId = (int) DB::table('faq_categories')->where('slug', 'general')->value('id');
        $faq = app(FaqService::class)->save([
            'question' => 'FT36 Question',
            'answer' => self::HOSTILE,
            'faq_category_id' => $categoryId,
            'status' => ContentStatus::Published->value,
        ]);
        $this->assertSanitised((string) DB::table('faqs')->where('id', $faq->getKey())->value('answer'));

        // Published, then hand-edited in the database: rendering sanitises again.
        $this->publisher()->publish($section);
        $page = $this->publisher()->publish($page);

        DB::table('pages')->where('id', $page->getKey())->update([
            'published_content' => '<p>FT36 Raw Page Row</p><script>alert("ft36-raw-page")</script><a href="javascript:alert(1)">FT36 raw link</a>',
        ]);

        $snapshot = $this->snapshotOf($section);
        $snapshot['fields']['body'] = '<p>FT36 Raw Section Row</p><script>alert("ft36-raw-section")</script><img src="https://cdn.example.test/x.png" onerror="alert(\'ft36-raw-onerror\')">';
        DB::table('website_sections')->where('id', $section->getKey())->update(['published_content' => json_encode($snapshot)]);

        $this->bumpPublicCache('FT-36 hand-edited rows');

        $pageHtml = (string) $this->get('/ft36-page')->assertOk()->getContent();
        $this->assertStringContainsString('FT36 Raw Page Row', $pageHtml);
        $this->assertStringNotContainsString('ft36-raw-page', $pageHtml, 'A <script> written straight into the database never renders.');
        $this->assertStringNotContainsString('javascript:alert(1)', $pageHtml);

        $homeHtml = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('FT36 Raw Section Row', $homeHtml);
        $this->assertStringNotContainsString('ft36-raw-section', $homeHtml);
        $this->assertStringNotContainsString('ft36-raw-onerror', $homeHtml);
    }

    /** §9 Anonymous visitor */
    public function test_anonymous_visitor_sees_only_published_rows_and_never_draft_columns(): void
    {
        $now = Carbon::now();

        // A live section with a newer draft, and a section taken down with a reason.
        $live = $this->placeRichContent('ISO Live Heading');
        $this->sections()->saveDraft($live, ['heading' => 'ISO Draft Secret Heading']);

        $down = $this->placeRichContent('ISO Unpublished Heading');
        $this->publisher()->unpublish($down, 'ISO-UNPUBLISH-REASON-SECRET');

        // A revision label never reaches the public site.
        $labelled = $this->placeRichContent('ISO Labelled Heading', publish: false);
        $this->publisher()->publish($labelled, 'ISO-REVISION-LABEL-SECRET');

        // FAQs of the seeded category: one published, one draft; then the FAQ section is republished.
        $categoryId = (int) DB::table('faq_categories')->where('slug', 'general')->value('id');

        foreach (['ISO Published Question' => ContentStatus::Published, 'ISO Draft Question' => ContentStatus::Draft] as $question => $status) {
            DB::table('faqs')->insert([
                'faq_category_id' => $categoryId,
                'question' => $question,
                'answer' => '<p>'.$question.' answer.</p>',
                'status' => $status->value,
                'is_featured' => false,
                'sort_order' => 500,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->publisher()->publish($this->seededSection('faq'));

        // A draft CTA block placed in a published CTA section resolves to nothing.
        $draftBlock = (int) DB::table('cta_blocks')->insertGetId([
            'key' => 'iso_draft_cta',
            'name' => 'ISO draft CTA',
            'variant' => 'banner',
            'heading' => 'ISO Draft CTA Heading',
            'primary_style' => 'primary',
            'secondary_style' => 'outline',
            'status' => ContentStatus::Draft->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cta = $this->sections()->place('cta', SectionPlacement::Home);
        $this->publisher()->publish($this->sections()->saveDraft($cta, ['cta_ref' => $draftBlock]));

        // A draft page.
        $this->makePage('iso-draft-page', 'ISO Draft Page', '<p>ISO draft page body</p>', publish: false);

        $this->bumpPublicCache('§9 fixtures');

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('ISO Live Heading', $html);
        $this->assertStringContainsString('ISO Published Question', $html);

        foreach (['ISO Draft Secret Heading', 'ISO Unpublished Heading', 'ISO-UNPUBLISH-REASON-SECRET', 'ISO-REVISION-LABEL-SECRET', 'ISO Draft Question', 'ISO Draft CTA Heading'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, sprintf('"%s" must never reach an anonymous visitor.', $forbidden));
        }

        // A draft page is a 404, never a 403: its existence is not public information.
        $this->get('/iso-draft-page')->assertNotFound()->assertDontSee('ISO draft page body');

        // The public read never selects the draft or the bookkeeping columns.
        $columns = WebsiteSection::query()->forPublic(SectionPlacement::Home)->getQuery()->columns ?? [];
        $selected = array_map(static fn (mixed $column): string => (string) preg_replace('~^.*\.~', '', (string) $column), $columns);

        $this->assertNotSame([], $selected, 'The public section read names its columns.');

        foreach (['content', 'content_hash', 'created_by', 'updated_by', 'unpublished_reason', 'name'] as $column) {
            $this->assertNotContains($column, $selected, sprintf('The public section read must not select %s.', $column));
        }

        $pageColumns = array_map(static fn (mixed $column): string => (string) preg_replace('~^.*\.~', '', (string) $column), Page::query()->forPublic('iso-draft-page')->getQuery()->columns ?? []);

        foreach (['content', 'content_hash', 'created_by', 'updated_by', 'unpublished_reason'] as $column) {
            $this->assertNotContains($column, $pageColumns, sprintf('The public page read must not select %s.', $column));
        }

        $this->assertNull(Page::query()->forPublic('iso-draft-page')->first(), 'A draft page is not in the public read at all.');
    }

    /** §9 Student / Teacher / Client / Collaborator panels */
    public function test_portal_users_are_ordinary_visitors_and_are_refused_every_admin_cms_route(): void
    {
        $hero = $this->publishHeroHeading('ISO Portal Live Hero');
        $hero = $this->sections()->saveDraft($hero, ['heading' => 'ISO Portal Draft Hero']);
        $publishedHash = $this->sectionRow($hero)->published_hash;
        $page = $this->makePage('iso-portal-draft', 'ISO Portal Draft Page', '<p>ISO portal draft body</p>', publish: false);

        $reads = [
            route('admin.website.index'),
            route('admin.website.sections.index', ['placement' => SectionPlacement::Home->value]),
            route('admin.website.sections.edit', $hero),
            route('admin.website.pages.index'),
            route('admin.website.pages.edit', $page),
            route('admin.website.menus.index'),
            route('admin.website.media.index'),
            route('admin.website.seo.index'),
            route('admin.website.faqs.index'),
            route('admin.website.cta-blocks.index'),
        ];

        foreach (['Student', 'Teacher', 'Client', 'Collaborator'] as $role) {
            $user = $this->createUserWithRole($role);

            foreach ($reads as $url) {
                $this->actingAs($user)
                    ->getJson($url)
                    ->assertForbidden()
                    ->assertDontSee('ISO Portal Draft Hero')
                    ->assertDontSee('ISO portal draft body');
            }

            $this->actingAs($user)
                ->postJson(route('admin.website.sections.publish', $hero))
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson(route('admin.website.pages.publish', $page))
                ->assertForbidden();

            $this->assertSame($publishedHash, $this->sectionRow($hero)->published_hash, sprintf('A %s cannot publish a section.', strtolower($role)));
            $this->assertSame(ContentStatus::Draft->value, DB::table('pages')->where('id', $page->getKey())->value('status'), sprintf('A %s cannot publish a page.', strtolower($role)));

            // On the public site they are ordinary visitors.
            $this->actingAs($user)
                ->get('/?preview=1')
                ->assertOk()
                ->assertSee('ISO Portal Live Hero')
                ->assertDontSee('ISO Portal Draft Hero');

            $this->actingAs($user)
                ->get('/iso-portal-draft?preview=1')
                ->assertNotFound()
                ->assertDontSee('ISO portal draft body');

            $this->actingAs($user)
                ->get(route('site.preview.page', $page))
                ->assertNotFound()
                ->assertDontSee('ISO portal draft body');

            $this->becomeGuest();
        }
    }

    private function assertSanitised(string $html): void
    {
        $this->assertStringContainsString('FT36 Safe paragraph', $html, 'Safe prose survives.');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('ft36-script-body', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.test', $html, 'An iframe from a host outside the allowlist is removed.');
        $this->assertStringContainsString('https://www.youtube.com/embed/ft36video', $html, 'A YouTube embed survives.');
    }
}
