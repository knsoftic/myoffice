<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Services\Cms\CtaBlockService;
use App\Services\Cms\FaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Cms\Behaviour\Fixtures\LiveFeedSectionProvider;
use Tests\Feature\Cms\Behaviour\Fixtures\ThrowingSectionProvider;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 1 regressions for what a published page renders *live* rather than frozen at publish.
 *
 *   · phase-03 §6.1 [D-W3-11]: an `is_live` section type's `SectionDataProvider` runs at render time — for
 *     the public page and the preview — so a row created after the section was published appears without
 *     re-publishing it, and a provider that throws never takes the page down (INV-2).
 *   · §2.15 / §9: CTA blocks and FAQs are status-gated and live. A block or question set back to draft,
 *     archived or trashed leaves the public page at once; an edit to a published one reaches it at once —
 *     in both cases without re-publishing the referencing section.
 */
final class LiveReferencesAndProvidersTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        LiveFeedSectionProvider::$calls = 0;
    }

    protected function tearDown(): void
    {
        $this->forgetTestSectionTypes();

        parent::tearDown();
    }

    public function test_live_section_provider_is_resolved_at_render_time_not_frozen_at_publish(): void
    {
        $this->registerLiveType('ft_live_feed', LiveFeedSectionProvider::class);

        $section = $this->sections()->place('ft_live_feed', SectionPlacement::Home);
        $section = $this->publisher()->publish($this->sections()->saveDraft($section, ['heading' => 'FT Live Feed Heading']));
        $publishedHash = $this->sectionRow($section)->published_hash;

        $this->assertNull($this->snapshotOf($section)['provider'] ?? null, 'A live provider is never frozen into the snapshot.');

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT Live Feed Heading')->assertDontSee('Live Feed Card One');
        $this->assertGreaterThan(0, LiveFeedSectionProvider::$calls, 'The provider ran while the page rendered.');

        // A row created after the section went live; the section itself is not touched.
        $this->makePage('live-feed-one', 'Live Feed Card One');

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT Live Feed Heading')->assertSee('Live Feed Card One');

        $this->assertSame($publishedHash, $this->sectionRow($section)->published_hash, 'The section was never re-published.');

        // The draft preview goes through the same render path.
        $this->makePage('live-feed-two', 'Live Feed Card Two');

        $this->actingAs($this->createSuperAdmin())
            ->get('/?preview=1')
            ->assertOk()
            ->assertSee('Live Feed Card Two');
    }

    public function test_a_live_provider_that_throws_never_takes_the_page_down(): void
    {
        $this->registerLiveType('ft_broken_feed', ThrowingSectionProvider::class);

        $section = $this->sections()->place('ft_broken_feed', SectionPlacement::Home);
        $this->publisher()->publish($this->sections()->saveDraft($section, ['heading' => 'FT Broken Feed Heading']));
        $hero = (string) data_get($this->snapshotOf($this->seededSection('hero')), 'fields.heading');

        Log::spy();

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT Broken Feed Heading')->assertSee($hero);

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message, mixed $context = []): bool => is_array($context) && ($context['section_key'] ?? null) === 'ft_broken_feed')
            ->atLeast()->once();
    }

    public function test_an_unpublished_or_trashed_cta_block_leaves_the_page_and_an_edit_reaches_it_without_republishing(): void
    {
        $section = $this->seededSection('cta');
        $publishedHash = $this->sectionRow($section)->published_hash;

        /** @var CtaBlock $block */
        $block = CtaBlock::query()->findOrFail((int) $this->sectionRow($section)->cta_block_id);
        $heading = (string) $block->heading;
        $blocks = app(CtaBlockService::class);

        $this->get('/')->assertOk()->assertSee($heading);

        $blocks->save(['status' => ContentStatus::Draft->value], $block);
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee($heading);

        $blocks->save(['status' => ContentStatus::Archived->value], $block->fresh());
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee($heading);

        $blocks->save(['status' => ContentStatus::Published->value, 'heading' => 'FT Live CTA Heading Edited'], $block->fresh());
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT Live CTA Heading Edited')->assertDontSee($heading);

        // Trashed below the service's in-use guard: gone from the page, not rendered from the snapshot.
        DB::table('cta_blocks')->where('id', $block->getKey())->update(['deleted_at' => Carbon::now()]);
        $this->bumpPublicCache('CTA trashed below the service');

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee('FT Live CTA Heading Edited')->assertDontSee($heading);

        $this->assertSame($publishedHash, $this->sectionRow($section)->published_hash, 'The CTA section was never re-published.');
    }

    public function test_an_unpublished_or_trashed_faq_leaves_the_page_and_a_disabled_category_takes_its_questions_with_it(): void
    {
        $section = $this->seededSection('faq');
        $publishedHash = $this->sectionRow($section)->published_hash;
        $faqs = app(FaqService::class);

        /** @var FaqCategory $general */
        $general = FaqCategory::query()->where('slug', 'general')->firstOrFail();

        $faq = $faqs->save([
            'question' => 'FT live question marker?',
            'answer' => '<p>FT live answer marker.</p>',
            'faq_category_id' => (int) $general->getKey(),
            'status' => ContentStatus::Published->value,
        ]);

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT live question marker?')->assertSee('FT live answer marker.');

        $faqs->toggle($faq, ContentStatus::Draft);
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee('FT live question marker?');

        $faqs->toggle($this->faq($faq), ContentStatus::Archived);
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee('FT live question marker?');

        $faqs->toggle($this->faq($faq), ContentStatus::Published);
        $faqs->save(['answer' => '<p>FT edited live answer.</p>'], $this->faq($faq));
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT live question marker?')->assertSee('FT edited live answer.')->assertDontSee('FT live answer marker.');

        $faqs->delete($this->faq($faq));
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee('FT live question marker?');

        // A disabled category takes its published questions off the page.
        $seeded = (string) DB::table('faqs')
            ->where('faq_category_id', $general->getKey())
            ->where('status', ContentStatus::Published->value)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->value('question');

        $this->assertNotSame('', $seeded, 'The seeded General category has a published question.');
        $this->get('/')->assertOk()->assertSee($seeded);

        $faqs->saveCategory(['is_enabled' => false], $general);
        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee($seeded);

        $this->assertSame($publishedHash, $this->sectionRow($section)->published_hash, 'The FAQ section was never re-published.');
    }

    /**
     * @param  class-string  $provider
     */
    private function registerLiveType(string $key, string $provider): void
    {
        $this->registerTestSectionType($key, [
            'label' => 'Review live feed',
            'group' => 'content',
            'placements' => [SectionPlacement::Home->value => 910],
            'unique' => false,
            'required' => false,
            'is_live' => true,
            'provider' => $provider,
            'view' => 'site.sections.services',
            'fields' => [
                'heading' => ['label' => 'Heading', 'type' => 'text', 'default' => null],
            ],
            'repeaters' => [],
            'media' => [],
        ]);
    }

    private function faq(Faq $faq): Faq
    {
        /** @var Faq */
        return Faq::query()->withTrashed()->findOrFail($faq->getKey());
    }
}
