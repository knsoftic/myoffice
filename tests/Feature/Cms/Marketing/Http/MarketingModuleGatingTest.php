<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Models\Module;
use App\Services\Core\ModuleService;
use App\Support\Modules;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The gates in front of the phase-04 screens and pages (§4, §7.1, §7.3, D26):
 *
 *   · §11 test 57 — disabling `blog_posts` (through `ModuleService`, with the D63 reason) 403s every admin
 *     blog route for a Super Admin and makes `/blog`, `/blog/{slug}`, `/blog/category/{slug}` and
 *     `/blog/tag/{slug}` a 404; re-enabling restores them with every row count unchanged;
 *   · the same for each of the fifteen modules: its admin routes close for everyone, its sidebar entry
 *     leaves, its own public routes 404 (never 403 — a disabled feature leaves no trace), nothing is written;
 *   · §11 test 36 (the switch half) and §7.1's other switches: `website.careers_enabled`,
 *     `website.team_page_enabled`, `website.portfolio_detail_enabled`, `maintenance.contact_form_enabled`.
 */
final class MarketingModuleGatingTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_contract_57_disabling_blog_posts_closes_the_admin_blog_for_super_admin_and_404s_the_public_blog(): void
    {
        $super = $this->createSuperAdmin();
        $category = $this->makeTerm('blog_categories');
        $post = $this->makeBlogPost(null, 'published', ['blog_category_id' => (int) $category->getKey()], ['Laravel']);
        $tag = DB::table('blog_tags')->whereRaw('LOWER(name) = ?', ['laravel'])->first();

        $this->assertNotNull($tag, 'The post carries its tag.');

        $public = [
            route('site.blog.index'),
            route('site.blog.show', ['blogPost' => $post->slug]),
            route('site.blog.category', ['blogCategory' => $category->getAttribute('slug')]),
            route('site.blog.tag', ['blogTag' => $tag->slug]),
        ];

        foreach ($public as $url) {
            $this->get($url)->assertOk();
        }

        $table = array_filter($this->marketingRouteTable(), static fn (array $entry): bool => str_starts_with($entry['permission'], 'blog_posts.'));
        $this->assertCount(14, $table, 'Integration §5.1 registers 14 blog post admin routes.');

        $prepared = [];

        foreach ($table as $name => $entry) {
            $prepared[$name] = $this->prepareMarketingRequest($name, $entry);
        }

        $counts = $this->marketingRowCounts();

        $this->disableThroughTheSwitchboard('blog_posts', 'Pausing the blog for a content audit');
        $this->assertFalse(Modules::enabled('blog_posts'));

        $before = $this->marketingFingerprint();

        foreach ($prepared as $name => $request) {
            foreach ([true, false] as $json) {
                $status = $this->actingAs($super)->sendPreparedMarketing($request, $json)->getStatusCode();

                $this->assertSame(403, $status, sprintf('With blog_posts disabled, %s %s must be 403 for a Super Admin (%s); it answered %d.', $request['method'], $name, $json ? 'JSON' : 'HTML', $status));
            }
        }

        $this->assertSame($before, $this->marketingFingerprint(), 'A request to the disabled blog wrote something.');
        $this->assertNotContains('Blog Posts', $this->cmsSidebarLabels($super));
        $this->assertContains('Blog Categories', $this->cmsSidebarLabels($super), 'Only the disabled module leaves the sidebar.');

        $this->signOut();

        foreach ($public as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->enableThroughTheSwitchboard('blog_posts');

        foreach ($public as $url) {
            $this->get($url)->assertOk();
        }

        $this->actingAs($super)->get(route('admin.blog-posts.index'))->assertOk()->assertSee((string) $post->title, false);
        $this->assertContains('Blog Posts', $this->cmsSidebarLabels($super));
        $this->assertSame($counts, $this->marketingRowCounts(), 'Disabling and re-enabling the blog must leave every row count unchanged.');
    }

    /**
     * Each of the fifteen modules closes exactly its own admin routes and sidebar entry, for a Super Admin
     * too, 404s exactly its own public routes, and writes nothing.
     */
    public function test_module_gating_closes_each_marketing_module_and_404s_its_own_public_routes(): void
    {
        $super = $this->createSuperAdmin();
        $table = $this->marketingRouteTable();
        $prepared = [];

        foreach ($table as $name => $entry) {
            $prepared[$name] = $this->prepareMarketingRequest($name, $entry);
        }

        $publicRoutes = $this->publicRoutesByModule();
        $labels = $this->marketingSidebarEntries();
        $modules = $this->marketingModules();

        foreach ($modules as $position => $module) {
            $counts = $this->marketingRowCounts();

            $this->switchModule($module, false);

            $before = $this->marketingFingerprint();
            $closed = 0;

            foreach ($prepared as $name => $request) {
                if (explode('.', $table[$name]['permission'], 2)[0] !== $module) {
                    continue;
                }

                $closed++;
                $status = $this->actingAs($super)->sendPreparedMarketing($request, true)->getStatusCode();

                $this->assertSame(403, $status, sprintf('With %s disabled, %s %s must be 403 for a Super Admin; it answered %d.', $module, $request['method'], $name, $status));
            }

            $this->assertGreaterThan(0, $closed, sprintf('%s owns admin routes.', $module));
            $this->assertSame($before, $this->marketingFingerprint(), sprintf('A request to the disabled %s module wrote something.', $module));

            // Another module keeps working.
            $neighbour = $modules[($position + 1) % count($modules)];
            $this->actingAs($super)->get(route($this->marketingIndexRoutes()[$neighbour]))->assertOk();

            $sidebar = $this->cmsSidebarLabels($super);

            foreach ($labels as $label => $entry) {
                $entry['module'] === $module
                    ? $this->assertNotContains($label, $sidebar, sprintf('%s must leave the sidebar while %s is disabled.', $label, $module))
                    : $this->assertContains($label, $sidebar, sprintf('%s must stay in the sidebar while only %s is disabled.', $label, $module));
            }

            $this->signOut();

            foreach ($publicRoutes[$module] ?? [] as [$method, $url, $data]) {
                $status = $this->sendCms($method, $url, $data, false)->getStatusCode();

                $this->assertSame(404, $status, sprintf('With %s disabled, %s %s must be a 404 (never 403, D26); it answered %d.', $module, $method, $url, $status));
            }

            $this->switchModule($module, true);

            $this->assertSame($counts, $this->marketingRowCounts(), sprintf('Switching %s off and on must touch no row.', $module));
            $this->actingAs($super)->get(route($this->marketingIndexRoutes()[$module]))->assertOk();
        }

        // Everything is back: each module's own public pages answer again.
        $this->signOut();

        foreach ($publicRoutes as $module => $routes) {
            foreach ($routes as [$method, $url]) {
                if ($method === 'GET') {
                    $this->get($url)->assertOk();
                }
            }
        }
    }

    /**
     * §11 test 36 (the switch half): `website.careers_enabled = false` makes `/careers`, the opening and the
     * apply POST a 404 and stores nothing.
     */
    public function test_contract_36_careers_switched_off_404s_the_careers_pages_and_the_apply_post(): void
    {
        $job = $this->makeJobOpening('open');

        // The detail page is never served from the public cache, so it proves the page is live before the switch.
        $this->get(route('site.careers.show', ['jobOpening' => $job->slug]))->assertOk()->assertSee((string) $job->title, false);

        settings_repo()->set('website.careers_enabled', false);

        $before = $this->marketingFingerprint();

        $this->get(route('site.careers.index'))->assertNotFound();
        $this->get(route('site.careers.show', ['jobOpening' => $job->slug]))->assertNotFound();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])
            ->post(route('site.careers.apply', ['jobOpening' => $job->slug]), array_merge($this->spamSafeFields(), [
                'applicant_name' => 'Switched off applicant',
                'email' => 'switched-off@example.com',
                'phone' => '+92 300 7654321',
                'cv' => $this->fakeCv(),
            ]))
            ->assertNotFound();

        $this->assertSame($before, $this->marketingFingerprint(), 'An application to switched-off careers stored something.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'An application to switched-off careers wrote a CV.');
    }

    /**
     * §7.1's remaining switches: the team page, the portfolio detail pages and the contact form POST.
     */
    public function test_the_public_page_switches_404_their_routes(): void
    {
        $member = $this->makeTeamMember(true);
        $item = $this->makePortfolioItem(true);

        settings_repo()->set('website.team_page_enabled', false);
        settings_repo()->set('website.portfolio_detail_enabled', false);
        settings_repo()->set('maintenance.contact_form_enabled', false);

        $before = $this->marketingFingerprint();

        $this->get(route('site.team.index'))->assertNotFound()->assertDontSee((string) $member->name, false);
        $this->get(route('site.portfolio.show', ['portfolioItem' => $item->slug]))->assertNotFound();
        $this->get(route('site.portfolio.index'))->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62'])
            ->post(route('site.contact.store'), array_merge($this->spamSafeFields(), [
                'inquiry_type' => 'general',
                'name' => 'Switched off visitor',
                'email' => 'switched-off-visitor@example.com',
                'message' => 'The contact form is switched off, so this must go nowhere.',
            ]))
            ->assertNotFound();

        $this->assertSame($before, $this->marketingFingerprint(), 'A switched-off public page or form stored something.');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Each module's own public routes, with a published row behind every detail URL.
     *
     * @return array<string, list<array{0: string, 1: string, 2: array<string, mixed>}>>
     */
    private function publicRoutesByModule(): array
    {
        $service = $this->makeService(true);
        $item = $this->makePortfolioItem(true);
        $this->makeTeamMember(true);
        $category = $this->makeTerm('blog_categories');
        $post = $this->makeBlogPost(null, 'published', ['blog_category_id' => (int) $category->getKey()], ['Gating']);
        $tag = DB::table('blog_tags')->whereRaw('LOWER(name) = ?', ['gating'])->first();
        $job = $this->makeJobOpening('open');

        return [
            'services' => [
                ['GET', route('site.services.index'), []],
                ['GET', route('site.services.show', ['service' => $service->slug]), []],
            ],
            'portfolio' => [
                ['GET', route('site.portfolio.index'), []],
                ['GET', route('site.portfolio.show', ['portfolioItem' => $item->slug]), []],
            ],
            'team' => [
                ['GET', route('site.team.index'), []],
            ],
            'blog_posts' => [
                ['GET', route('site.blog.index'), []],
                ['GET', route('site.blog.show', ['blogPost' => $post->slug]), []],
                ['GET', route('site.blog.category', ['blogCategory' => $category->getAttribute('slug')]), []],
                ['GET', route('site.blog.tag', ['blogTag' => $tag->slug]), []],
            ],
            'jobs' => [
                ['GET', route('site.careers.index'), []],
                ['GET', route('site.careers.show', ['jobOpening' => $job->slug]), []],
                ['POST', route('site.careers.apply', ['jobOpening' => $job->slug]), array_merge($this->spamSafeFields(), [
                    'applicant_name' => 'Gated applicant',
                    'email' => 'gated-applicant@example.com',
                    'phone' => '+92 300 1112223',
                ])],
            ],
        ];
    }

    private function disableThroughTheSwitchboard(string $slug, string $reason): void
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        app(ModuleService::class)->setEnabled($module, false, $reason);
        Modules::flushCache();
    }

    private function enableThroughTheSwitchboard(string $slug): void
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        app(ModuleService::class)->setEnabled($module, true);
        Modules::flushCache();
    }
}
