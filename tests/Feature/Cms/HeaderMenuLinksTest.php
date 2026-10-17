<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Models\Module;
use App\Services\Core\ModuleService;
use App\Support\Cms\PublicPageAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The header menu never links to a page that answers 404 (`PublicPageAvailability`), every page has a
 * title of its own, and `cms:landing-page --menu` gives the admission form a way in from the header.
 *
 * Found by clicking through the header: "Fees" pointed at /fee-structure, which is a 404 until
 * `website.fee_structure_page_enabled` is on — and that switch defaults to off.
 */
final class HeaderMenuLinksTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('company.name', 'Example Softic');
    }

    #[Test]
    public function a_menu_link_to_a_switched_off_page_is_not_offered(): void
    {
        $this->headerLink('Fees', '/fee-structure');
        $this->enableModulesOf('site.fees.index');

        $this->setSetting('website.fee_structure_page_enabled', false);
        $this->bumpPublicCache();

        $this->get('/fee-structure')->assertNotFound();
        $this->assertStringNotContainsString('href="/fee-structure"', $this->header(), 'The header linked to a page that answers 404.');

        $this->setSetting('website.fee_structure_page_enabled', true);
        $this->bumpPublicCache();

        $this->get('/fee-structure')->assertOk();
        $this->assertStringContainsString('href="/fee-structure"', $this->header());
    }

    #[Test]
    public function a_menu_link_to_a_disabled_module_is_not_offered(): void
    {
        $this->headerLink('Our services', '/services');

        $module = Module::query()->where('slug', 'services')->firstOrFail();
        app(ModuleService::class)->setEnabled($module, true);
        $this->assertStringContainsString('href="/services"', $this->header());

        app(ModuleService::class)->setEnabled($module->fresh(), false, 'Header links follow the module switch');

        $this->get('/services')->assertNotFound();
        $this->assertStringNotContainsString('href="/services"', $this->header());
    }

    #[Test]
    public function every_page_switch_names_the_setting_its_page_reads(): void
    {
        foreach (PublicPageAvailability::SWITCHES as $name => [$key]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, sprintf('%s is not a route.', $name));

            if ($route->parameterNames() !== []) {
                continue; // a detail page needs a record; its index shares the switch
            }

            $this->enableModulesOf($name);

            $this->setSetting($key, false);
            $this->bumpPublicCache();
            $this->get(route($name))->assertNotFound();
            $this->assertFalse(PublicPageAvailability::isOpen(route($name, [], false), Request::create('/')));

            $this->setSetting($key, true);
            $this->bumpPublicCache();
            $this->assertNotSame(404, $this->get(route($name))->getStatusCode(), sprintf('%s still 404s with %s on: the map names the wrong switch.', $name, $key));
        }
    }

    #[Test]
    public function external_links_anchors_and_unknown_paths_are_left_alone(): void
    {
        $this->assertTrue(PublicPageAvailability::isOpen('https://www.linkedin.com/company/example'));
        $this->assertTrue(PublicPageAvailability::isOpen('#faq'));
        $this->assertTrue(PublicPageAvailability::isOpen('/#about'));
        $this->assertTrue(PublicPageAvailability::isOpen('mailto:office@example.test'));
        $this->assertTrue(PublicPageAvailability::isOpen(null));
    }

    #[Test]
    public function a_page_without_an_seo_title_of_its_own_is_titled_by_the_page(): void
    {
        $this->enableModulesOf('site.courses.index');
        $this->bumpPublicCache();

        // The catalogue has no seo_meta row, so the pipeline's title is the bare company name.
        $this->get('/courses')->assertOk()->assertSee('<title>Courses | Example Softic</title>', false);
        $this->get('/')->assertOk()->assertSee('<title>Example Softic</title>', false);
    }

    #[Test]
    public function the_landing_command_swaps_the_seeded_header_button_for_apply_now_and_keeps_an_editors(): void
    {
        $this->headerButton('View courses', '/courses');

        $this->artisan('cms:landing-page', ['--menu' => true])->assertSuccessful();

        $this->assertSame('/admission', data_get($this->seededSection('header', SectionPlacement::GlobalHeader)->fresh()->content, 'cta_button.url'));
        $this->assertSame('Apply now', data_get($this->seededSection('header', SectionPlacement::GlobalHeader)->fresh()->content, 'cta_button.label'));

        $this->headerButton('Book a call', '/contact');

        $this->artisan('cms:landing-page', ['--menu' => true])->assertSuccessful();

        $this->assertSame('/contact', data_get($this->seededSection('header', SectionPlacement::GlobalHeader)->fresh()->content, 'cta_button.url'), 'A button an editor chose was replaced.');
    }

    private function headerLink(string $label, string $url): void
    {
        $this->menus()->storeItem($this->menuAt(MenuLocation::Header), [
            'label' => $label,
            'link_type' => 'url',
            'url' => $url,
            'is_enabled' => true,
        ]);

        // The header carries its menu as a publish-time snapshot.
        $this->publisher()->publish($this->seededSection('header', SectionPlacement::GlobalHeader)->fresh());
    }

    private function headerButton(string $label, string $url): void
    {
        $header = $this->sections()->saveDraft($this->seededSection('header', SectionPlacement::GlobalHeader), [
            'cta_button_enabled' => true,
            'cta_button' => ['label' => $label, 'url' => $url, 'style' => 'primary', 'new_tab' => false],
        ]);

        $this->publisher()->publish($header);
    }

    private function enableModulesOf(string $routeName): void
    {
        foreach (Route::getRoutes()->getByName($routeName)->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'site_module:')) {
                foreach (explode(',', substr($middleware, strlen('site_module:'))) as $slug) {
                    $module = Module::query()->where('slug', trim($slug))->first();

                    if ($module !== null) {
                        app(ModuleService::class)->setEnabled($module, true);
                    }
                }
            }
        }
    }

    private function header(): string
    {
        $this->bumpPublicCache();
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<header.*?</header>~s', $html, $match), 'The home page has no header.');

        return $match[0];
    }
}
