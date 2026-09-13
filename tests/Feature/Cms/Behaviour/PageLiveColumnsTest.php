<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\PageLayout;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 1 regression — [D-W3-10], INV-1, §6.4 "saveDraft writes content only", §9 "an SEO edit goes
 * live when a publisher publishes it".
 *
 * `pages.edit` without `pages.change_status` (the seeded SEO Expert) saves drafts and nothing else: it cannot
 * retitle, re-address, re-layout, re-banner or re-template a live page, and it cannot edit a scheduled page
 * whose content would publish itself unreviewed. A publisher can do all of it.
 */
final class PageLiveColumnsTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const EDITOR = ['pages.view_any', 'pages.view', 'pages.edit'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_pages_edit_alone_cannot_change_what_a_live_page_renders(): void
    {
        $page = $this->makePage('ft-live-columns', 'FT Live Columns Title', '<p>FT live columns body.</p>');
        $editor = $this->createUserWithPermissions(self::EDITOR);
        $update = route('admin.website.pages.update', $page);

        $this->actingAs($editor)
            ->get(route('admin.website.pages.edit', $page))
            ->assertOk()
            ->assertSee('This page is live. Its title, address, layout, excerpt, banner and template change only with the publish permission', false);

        foreach ([
            'title' => 'FT Hijacked Title',
            'layout' => PageLayout::Sections->value,
            'excerpt' => 'FT hijacked excerpt',
            'banner_heading' => 'FT Hijacked Banner',
            'banner_subheading' => 'FT hijacked subheading',
            'template' => 'site.pages.wide',
            'show_banner' => false,
        ] as $field => $value) {
            $this->actingAs($editor)
                ->putJson($update, [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        // The address breaks inbound links: the policy refuses it outright.
        $this->actingAs($editor)->putJson($update, ['slug' => 'ft-hijacked-address'])->assertForbidden();

        // The body is a draft: saved, never live.
        $this->actingAs($editor)->putJson($update, ['content' => '<p>FT editor draft body.</p>'])->assertOk();

        $row = DB::table('pages')->where('id', $page->getKey())->first();
        $this->assertSame('FT Live Columns Title', $row->title);
        $this->assertSame('ft-live-columns', $row->slug);
        $this->assertSame(PageLayout::Content->value, $row->layout);
        $this->assertNull($row->banner_heading);
        $this->assertNull($row->template);
        $this->assertSame(1, (int) $row->has_unpublished_changes, 'The draft body was saved.');

        // A caller with no Form Request is held to the same rule.
        $this->actingAs($editor);
        $this->assertThrows(
            fn () => $this->pages()->saveDraft($page->fresh(), ['title' => 'FT Service Hijack']),
            InvalidSectionContentException::class,
        );

        $this->becomeGuest();
        $this->get('/ft-live-columns')
            ->assertOk()
            ->assertSee('FT Live Columns Title')
            ->assertSee('FT live columns body.')
            ->assertDontSee('FT Hijacked')
            ->assertDontSee('FT editor draft body.');

        // A publisher may change the live columns.
        $publisher = $this->createUserWithPermissions([...self::EDITOR, 'pages.change_status']);

        $this->actingAs($publisher)->putJson($update, ['title' => 'FT Publisher Retitled'])->assertOk();

        $this->becomeGuest();
        $this->get('/ft-live-columns')->assertOk()->assertSee('FT Publisher Retitled');
    }

    public function test_pages_edit_alone_cannot_change_a_scheduled_page_before_it_publishes_itself(): void
    {
        $start = Carbon::parse('2026-09-13 10:00:00', 'UTC');
        $this->travelTo($start);

        $page = $this->makePage('ft-scheduled-edit', 'FT Scheduled Title', '<p>FT reviewed body.</p>', publish: false);
        $this->publisher()->schedule($page, $start->copy()->addHour());

        $editor = $this->createUserWithPermissions(self::EDITOR);
        $update = route('admin.website.pages.update', $page);

        $this->actingAs($editor)->putJson($update, ['content' => '<p>FT unreviewed body.</p>'])->assertForbidden();
        $this->actingAs($editor)->putJson($update, ['title' => 'FT Unreviewed Title'])->assertForbidden();

        $this->actingAs($editor);
        $this->assertThrows(
            fn () => $this->pages()->saveDraft($page->fresh(), ['content' => '<p>FT unreviewed body.</p>']),
            InvalidSectionContentException::class,
        );

        $this->assertStringNotContainsString('FT unreviewed body.', (string) DB::table('pages')->where('id', $page->getKey())->value('content'));

        $this->becomeGuest();
        $this->travelTo($start->copy()->addHours(2));
        $this->artisan('cms:publish-scheduled')->assertSuccessful();

        $this->assertSame(ContentStatus::Published, $this->statusOf($page));

        $this->get('/ft-scheduled-edit')
            ->assertOk()
            ->assertSee('FT Scheduled Title')
            ->assertSee('FT reviewed body.')
            ->assertDontSee('FT unreviewed body.');
    }
}
