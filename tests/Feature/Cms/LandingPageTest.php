<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\WebsiteSection;
use App\Support\Cms\SectionRegistry;
use App\Support\Cms\Sections\LandingSectionTypes;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The landing-page section types (`LandingSectionTypes`), the hero's badge and rating fields, and
 * `cms:landing-page`, which lays the home page out with them through the CMS services.
 */
final class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsRepository::class)->flush();
    }

    #[Test]
    public function the_three_types_are_registered_with_icons_the_editor_allows(): void
    {
        $allowed = collect(require base_path('resources/data/icons.php'))->flatten()->all();

        foreach (array_keys(LandingSectionTypes::definitions()) as $key) {
            $this->assertTrue(SectionRegistry::exists($key), sprintf('%s is not registered.', $key));
            $this->assertContains(SectionRegistry::icon($key), $allowed, sprintf('%s uses an icon the picker does not offer.', $key));
            $this->assertTrue(SectionRegistry::allowedIn($key, SectionPlacement::Home));
        }

        $this->assertTrue(SectionRegistry::hasRepeater('solutions', 'feature'));
        $this->assertTrue(SectionRegistry::hasRepeater('pricing', 'plan'));

        foreach (['badge_text', 'badge_tag', 'rating_value', 'rating_text'] as $field) {
            $this->assertNotNull(SectionRegistry::field('hero', $field), sprintf('hero.%s is missing.', $field));
            $this->assertNull(SectionRegistry::defaults('hero')[$field], 'A new hero field must default to empty.');
        }
    }

    #[Test]
    public function the_command_lays_out_the_home_page_and_running_it_again_adds_nothing(): void
    {
        $this->artisan('cms:landing-page', ['--hero' => true, '--menu' => true, '--tidy' => true])->assertSuccessful();

        foreach (['solutions' => 'solutions', 'signup_cta' => 'get-started'] as $key => $anchor) {
            $section = $this->homeSection($key);

            $this->assertNotNull($section, sprintf('%s was not placed.', $key));
            $this->assertSame($anchor, $section->anchor);
            $this->assertNotNull($section->published_at, sprintf('%s was not published.', $key));
        }

        $this->assertSame(12, DB::table('website_section_items')->where('website_section_id', $this->homeSection('solutions')->getKey())->where('group', 'feature')->count());

        // Course fees differ per course and live on /fee-structure, so no price table is placed.
        $this->assertNull($this->homeSection('pricing'), 'The command places no pricing section.');

        $old = $this->homeSection('cta');
        if ($old !== null) {
            $this->assertFalse((bool) $old->is_enabled, '--tidy switches the old banner off rather than deleting it.');
        }

        $labels = DB::table('menu_items')->whereNull('deleted_at')->pluck('label')->all();
        $this->assertContains('FAQs', $labels);
        $this->assertNotContains('Pricing', $labels, 'There is no pricing section to link to.');

        $sections = DB::table('website_sections')->whereNull('deleted_at')->count();
        $items = DB::table('menu_items')->whereNull('deleted_at')->count();

        $this->artisan('cms:landing-page', ['--hero' => true, '--menu' => true, '--tidy' => true])->assertSuccessful();

        $this->assertSame($sections, DB::table('website_sections')->whereNull('deleted_at')->count(), 'A second run adds no section.');
        $this->assertSame($items, DB::table('menu_items')->whereNull('deleted_at')->count(), 'A second run adds no link.');
    }

    #[Test]
    public function the_home_page_renders_the_landing_sections(): void
    {
        $this->artisan('cms:landing-page', ['--hero' => true, '--menu' => true, '--tidy' => true])->assertSuccessful();

        $html = $this->get(route('site.home'))->assertOk()->getContent();

        // Hero: the institute-and-software-house copy, the drawn preview with the statistics as chips, and
        // no rating — there is no verified one, so none is written.
        $this->assertStringContainsString('IT training &amp; software house', $html);
        $this->assertStringContainsString('Learn practical IT skills', $html);
        $this->assertStringContainsString('data-hero-preview', $html);
        $this->assertStringNotContainsString('/5<span class="sr-only"> stars', $html, 'No rating is invented.');

        // Solutions: a real tablist with both audiences.
        $this->assertStringContainsString('id="solutions"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('For students', $html);
        $this->assertStringContainsString('For businesses', $html);

        // No price table: course fees are per course.
        $this->assertStringNotContainsString('id="pricing"', $html);

        // The header carries its menu as a publish-time snapshot; the new link shows because the command
        // published the header again rather than only writing the menu row.
        $this->assertStringContainsString('<span>FAQs</span>', $html);

        // Sign-up banner: the email box never has a name, so no address reaches a query string.
        $this->assertStringContainsString('id="get-started"', $html);
        $this->assertStringContainsString('Ready to start a course or a project?', $html);
        $this->assertMatchesRegularExpression('/<input\s[^>]*id="signup-email-\d+"(?![^>]*\sname=)[^>]*>/s', $html);
    }

    #[Test]
    public function a_hero_without_the_new_fields_renders_as_before(): void
    {
        $html = $this->get(route('site.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('animate-ping', $html, 'No badge until an editor writes one.');
        $this->assertStringNotContainsString('/5<span class="sr-only"> stars', $html, 'No rating until an editor writes one.');
    }

    #[Test]
    public function a_section_type_with_no_content_renders_nothing(): void
    {
        foreach (['solutions' => ['feature' => []], 'pricing' => ['plan' => []]] as $key => $items) {
            $html = view('site.sections.'.$key, ['section' => null, 'content' => ['heading' => 'Heading'], 'items' => $items])->render();

            $this->assertSame('', trim($html), sprintf('An empty %s block must render nothing, not an empty grid.', $key));
        }

        $this->assertSame('', trim(view('site.sections.signup_cta', ['section' => null, 'content' => []])->render()), 'No heading, no banner.');
    }

    private function homeSection(string $key): ?WebsiteSection
    {
        return WebsiteSection::query()
            ->where('section_key', $key)
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->first();
    }
}
