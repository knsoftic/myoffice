<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http;

use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Menu;
use App\Models\Cms\WebsiteSection;
use App\Support\SettingsRepository;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Every phase-03 screen renders (§8), admin and public:
 *
 *   · every admin CMS screen, with its filters, for each placement and each seeded section editor
 *     (§8.3-§8.13), answers 200 for a Super Admin against the seeded day-one site;
 *   · the empty states the contract words (§8.4, §8.5, §8.9, §8.10, §8.13);
 *   · the public home page, the system pages, robots.txt, sitemap.xml and the branded 404 (§8.14);
 *   · FT-51 as a rendered-HTML assertion (Dusk is not installed): viewport, dark-mode counterparts, wide
 *     content in its own scroll container, no fixed width wider than a phone, no leaked error;
 *   · FT-37: a static scan of the public views for unescaped output.
 */
final class CmsScreensTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
    }

    /*
    |--------------------------------------------------------------------------
    | Admin screens
    |--------------------------------------------------------------------------
    */

    public function test_every_admin_cms_screen_renders_for_a_super_admin(): void
    {
        $super = $this->createSuperAdmin();

        foreach ($this->cmsRouteTable() as $name => $entry) {
            if ($entry['kind'] === 'write') {
                continue;
            }

            $request = $this->prepareCmsRequest($name, $entry, false);
            $response = $this->actingAs($super)->sendPreparedCms($request);

            $this->assertSame(200, $response->getStatusCode(), sprintf('The %s screen (%s) answered %d.', $name, $request['url'], $response->getStatusCode()));
        }

        $sectionsPage = $this->makeSectionsLayoutPage();
        $privacy = $this->seededSystemPage();
        $general = $this->seededFaqCategory();

        $urls = [
            route('admin.website.sections.index', ['placement' => SectionPlacement::GlobalHeader->value]),
            route('admin.website.sections.index', ['placement' => SectionPlacement::GlobalFooter->value]),
            route('admin.website.sections.index', ['placement' => SectionPlacement::Page->value, 'page_id' => $sectionsPage->getKey()]),
            route('admin.website.sections.available', ['placement' => SectionPlacement::Page->value, 'page_id' => $sectionsPage->getKey()]),
            route('admin.website.sections.available', ['placement' => SectionPlacement::GlobalHeader->value]),
            route('admin.website.sections.index', ['placement' => 'home', 'status' => 'published', 'enabled' => 'enabled', 'unpublished' => '0', 'search' => 'hero']),
            route('admin.website.statistics.index', ['mode' => 'auto', 'metric' => 'years_experience', 'enabled' => 'enabled', 'search' => 'Years']),
            route('admin.website.menus.index', ['search' => 'footer']),
            route('admin.website.pages.index', ['trashed' => '1']),
            route('admin.website.pages.index', ['status' => 'published', 'layout' => 'content', 'system' => 'system', 'missing_seo' => '1', 'unpublished' => '0', 'search' => 'privacy', 'sort' => 'title', 'direction' => 'desc']),
            route('admin.website.pages.edit', $sectionsPage),
            route('admin.website.pages.revisions.index', $sectionsPage),
            route('admin.website.pages.export', ['status' => 'published']),
            route('admin.website.cta-blocks.index', ['status' => 'published', 'variant' => 'banner', 'unused' => '0', 'search' => 'primary']),
            route('admin.website.cta-blocks.edit', $this->makeCtaBlock()),
            route('admin.website.faqs.index', ['category' => 'uncategorised']),
            route('admin.website.faqs.index', ['category' => (string) $general->getKey(), 'status' => 'published', 'featured' => '1', 'search' => 'contact']),
            route('admin.website.faq-categories.index', ['enabled' => 'enabled', 'search' => 'gen']),
            route('admin.website.seo.index', ['type' => 'page', 'robots' => 'index_follow', 'gap' => 'missing_title', 'search' => 'privacy', 'sort' => 'completeness', 'direction' => 'desc']),
            route('admin.website.seo.edit', ['target' => 'page:'.$privacy->getKey()]),
            route('admin.website.seo.sitemap.history', ['status' => 'ok']),
            route('admin.website.seo.export', ['robots' => 'index_follow']),
            route('admin.website.media.index', ['type' => 'image', 'unused' => '1', 'collection' => 'general', 'search' => 'fixture']),
        ];

        foreach (MenuLocation::cases() as $location) {
            $menu = Menu::query()->where('location', $location->value)->first();

            if ($menu !== null) {
                $urls[] = route('admin.website.menus.show', $menu);
                $urls[] = route('admin.website.menus.link-check', $menu);
            }
        }

        // Each section type's editor (§8.5-§8.8) and its revision history.
        $sections = WebsiteSection::query()->whereNull('page_id')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(6, $sections->count(), 'The seeder places header, hero, about, faq, cta and footer.');
        $sections->push($this->makeRichContentSection());

        foreach ($sections as $section) {
            $urls[] = route('admin.website.sections.edit', $section);
            $urls[] = route('admin.website.sections.revisions.index', $section);
        }

        foreach ($urls as $url) {
            $status = $this->actingAs($super)->get($url)->getStatusCode();

            $this->assertSame(200, $status, sprintf('%s answered %d.', $url, $status));
        }

        $this->actingAs($super)
            ->get(route('admin.website.seo.robots.preview', ['format' => 'text']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * The empty states the contract words, each reachable with one soft change inside the test's
     * transaction.
     */
    public function test_the_cms_screens_render_their_contract_empty_states(): void
    {
        $super = $this->createSuperAdmin();

        // §8.13 — no media yet (the seeder ships no images).
        $this->actingAs($super)->get(route('admin.website.media.index'))->assertOk()->assertSee('No images yet', false);

        // §8.9 — a menu with no items (the seeder fills only the header and the legal menu).
        $empty = $this->cmsMenu(MenuLocation::FooterPrimary);
        $this->assertSame(0, DB::table('menu_items')->where('menu_id', $empty->getKey())->whereNull('deleted_at')->count());
        $this->actingAs($super)->get(route('admin.website.menus.show', $empty))->assertOk()->assertSee('This menu has no items yet', false);

        // §8.4 — a new page composed of sections has none.
        $page = $this->makeSectionsLayoutPage();
        $this->actingAs($super)
            ->get(route('admin.website.sections.index', ['placement' => 'page', 'page_id' => $page->getKey()]))
            ->assertOk()
            ->assertSee('This page has no sections yet', false);

        // §8.5 — an empty statistics repeater says how many it takes.
        $hero = $this->cmsSection('hero');
        DB::table('website_section_items')->where('website_section_id', $hero->getKey())->update(['deleted_at' => Carbon::now()]);
        $this->actingAs($super)
            ->get(route('admin.website.sections.edit', $hero))
            ->assertOk()
            ->assertSee('No statistics yet', false)
            ->assertSee('Add up to 8', false);

        // §8.10 — no custom pages at all.
        DB::table('pages')->update(['deleted_at' => Carbon::now()]);
        $this->actingAs($super)->get(route('admin.website.pages.index'))->assertOk()->assertSee('No custom pages yet', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Public screens
    |--------------------------------------------------------------------------
    */

    public function test_the_public_screens_render_for_a_visitor(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<main id="content"', false)
            ->assertSee('site-footer-heading', false);

        foreach (['privacy-policy' => 'Privacy Policy', 'terms-of-service' => 'Terms of Service', 'refund-policy' => 'Refund Policy', 'course-policy' => 'Course Policy'] as $slug => $title) {
            $this->get('/'.$slug)->assertOk()->assertSee($title, false);
        }

        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $sitemap = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('<urlset', (string) $sitemap->getContent());

        // §8.14: an unknown slug still looks like the company's site — header and footer intact.
        $this->get('/no-such-page-here')
            ->assertNotFound()
            ->assertSee('Error 404', false)
            ->assertSee('<header', false)
            ->assertSee('site-footer-heading', false);

        // A draft page is a 404, never a 403: its existence is not public information (§9).
        $draft = $this->makeCmsPage();
        $this->get('/'.$draft->slug)->assertNotFound();
    }

    /**
     * FT-51 — "the home page and a custom page render at 375 / 768 / 1280 with no horizontal overflow,
     * every section has a dark: counterpart for its background and text, and the pages produce no console
     * error (smoke test, Dusk or a rendered-HTML assertion where Dusk is unavailable)".
     *
     * The server renders one HTML document for every width, so the rendered-HTML form is asserted once
     * per page: a device-width viewport; every light background and dark text utility carries its `dark:`
     * counterpart unless it sits inside a forced-dark scope (`class="dark"`); wide content (a table)
     * scrolls inside its own container (CLAUDE.md §6); no inline width wider than a 375 px phone; no
     * leaked error text; the Light / Dark / System control is on the page.
     */
    public function test_public_site_is_responsive_and_dark_mode_clean_in_the_rendered_html(): void
    {
        $table = '<table><thead><tr><th>Programme</th><th>Duration</th><th>Fee</th><th>Refund window</th><th>Conditions</th></tr></thead>'
            .'<tbody><tr><td>Full-stack web development bootcamp</td><td>Six months</td><td>PKR 150,000</td><td>Fourteen days</td><td>Refundable before the second class of the first batch week</td></tr></tbody></table>';

        $page = $this->makeCmsPage(true, ['content' => '<h2>Fees and refunds</h2><p>The fee schedule for every programme.</p>'.$table]);

        foreach (['/' => 'the home page', '/'.$page->slug => 'a custom page with a table', '/privacy-policy' => 'a system page'] as $uri => $label) {
            $html = (string) $this->get($uri)->assertOk()->getContent();

            $this->assertRenderedHtmlIsResponsiveAndDarkModeClean($html, $label);
        }

        $this->assertGreaterThan(0, substr_count((string) $this->get('/'.$page->slug)->getContent(), '<table'), 'The custom page must actually render its table for the overflow check to mean anything.');
    }

    /**
     * FT-37 — a static scan of the public views: `{!! !!}` only on `RichText::sanitize()` output (the map
     * embed goes through it too), and no other raw-output path (a PHP tag or an `echo`).
     */
    public function test_no_unescaped_output_in_site_views_static_scan(): void
    {
        $root = resource_path('views');
        $violations = [];
        $scanned = 0;

        foreach (['site', 'components/site'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $scanned++;
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                // Blade comments are documentation, not output; keep the line numbers.
                $code = (string) preg_replace_callback(
                    '/\{\{--.*?--\}\}/s',
                    static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
                    (string) file_get_contents($file->getPathname()),
                );

                foreach (preg_split('/\r\n|\r|\n/', $code) ?: [] as $index => $line) {
                    $at = $relative.':'.($index + 1);

                    if (str_contains($line, '{!!') && ! str_contains($line, 'RichText::sanitize(')) {
                        $violations[] = $at.' prints {!! !!} that is not RichText::sanitize() output';
                    }

                    if (preg_match('/<\?(php|=)/i', $line) === 1) {
                        $violations[] = $at.' opens a raw PHP tag';
                    }

                    if (preg_match('/(?<![\w$>\-])echo\b/', $line) === 1) {
                        $violations[] = $at.' echoes raw output';
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $scanned, 'The scan found too few public views to be meaningful.');
        $this->assertSame([], $violations, "Unescaped output in the public views (FT-37):\n".implode("\n", $violations));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertRenderedHtmlIsResponsiveAndDarkModeClean(string $html, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '/<meta\s+name="viewport"\s+content="[^"]*width=device-width[^"]*initial-scale=1/i',
            $html,
            $label.' must declare a device-width viewport.',
        );

        foreach (['Undefined variable', 'Undefined array key', 'ErrorException', 'Stack trace', 'Whoops'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, sprintf('%s leaks an error ("%s").', $label, $leak));
        }

        $this->assertStringContainsString('aria-label="Colour theme"', $html, $label.' must offer the Light / Dark / System control (§8.14).');

        $xpath = $this->xpathOf($html);
        $violations = [];

        foreach ($xpath->query('//*[@class]') ?: [] as $node) {
            if (! $node instanceof DOMElement || $this->insideForcedDarkScope($node)) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
            $darkBackground = $this->hasTokenStartingWith($tokens, 'dark:bg-');
            $darkText = $this->hasTokenStartingWith($tokens, 'dark:text-');

            foreach ($tokens as $token) {
                if (preg_match('/^bg-(white|(slate|gray|zinc|neutral|stone)-(50|100|200))$/', $token) === 1 && ! $darkBackground) {
                    $violations[] = sprintf('<%s class="%s"> has %s with no dark:bg- counterpart', $node->tagName, $node->getAttribute('class'), $token);
                }

                if (preg_match('/^text-(black|(slate|gray|zinc|neutral|stone)-(700|800|900|950))$/', $token) === 1 && ! $darkText) {
                    $violations[] = sprintf('<%s class="%s"> has %s with no dark:text- counterpart', $node->tagName, $node->getAttribute('class'), $token);
                }
            }
        }

        foreach ($xpath->query('//table') ?: [] as $table) {
            if (! $this->scrollsInsideItsOwnContainer($table)) {
                $violations[] = 'a <table> is not inside its own horizontal scroll container (overflow-x-auto)';
            }
        }

        foreach ($xpath->query('//*[@style]') ?: [] as $node) {
            if ($node instanceof DOMElement
                && preg_match('/(?:^|;)\s*(?:min-)?width\s*:\s*(\d+)px/i', $node->getAttribute('style'), $match) === 1
                && (int) $match[1] > 375) {
                $violations[] = sprintf('<%s style="%s"> is wider than a 375 px screen', $node->tagName, $node->getAttribute('style'));
            }
        }

        $this->assertSame([], $violations, sprintf("%s is not responsive / dark-mode clean (FT-51):\n%s", $label, implode("\n", array_unique($violations))));
    }

    private function xpathOf(string $html): DOMXPath
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function insideForcedDarkScope(DOMNode $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            $tokens = preg_split('/\s+/', trim($current->getAttribute('class'))) ?: [];

            if (in_array('dark', $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    private function scrollsInsideItsOwnContainer(DOMNode $table): bool
    {
        for ($current = $table; $current instanceof DOMElement; $current = $current->parentNode) {
            foreach (preg_split('/\s+/', trim($current->getAttribute('class'))) ?: [] as $token) {
                if (preg_match('/(^|:)overflow-(x-)?(auto|scroll)$/', $token) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasTokenStartingWith(array $tokens, string $prefix): bool
    {
        foreach ($tokens as $token) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
