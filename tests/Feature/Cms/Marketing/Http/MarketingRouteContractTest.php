<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Http\Middleware\EnsureSiteModuleEnabled;
use App\Support\PermissionRegistry;
use App\Support\SettingsRepository;
use App\Support\Sidebar;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The route contract of phase-04 §7 as integrated (integration §5, §6, §9 R-1 / R-2; §10.2 `RouteContractTest`
 * and `SidebarTest`):
 *
 *   · the 168 route names exist (153 admin, 15 public), each registered once;
 *   · every admin route carries `auth`, `active`, `panel:admin`, exactly one `module:` and exactly one `can:`
 *     of that module, declared by PermissionRegistry, and every `{parameter}` is numeric-only;
 *   · every public route carries `site` and never `can:` or `module:` (phase-03 INV-15); a content module
 *     gates its own public routes with `site_module:` (D26); `site.blog.preview` is `auth` + `active`;
 *   · every URI round-trips to its own name — literal segments are never swallowed by a `{parameter}` and
 *     no phase-04 public path reaches the CMS `/{slug}` catch-all; a parameter that resolves nothing is 404;
 *   · the fifteen Website sidebar entries appear in order, each agreeing with its route.
 */
final class MarketingRouteContractTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    /**
     * Integration §5.2: name => [method, URI, middleware aliases in order, as gathered].
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private const PUBLIC_ROUTES = [
        'site.services.index' => ['GET', 'services', ['web', 'site', 'site_module:services', 'site.cache']],
        'site.services.show' => ['GET', 'services/{service}', ['web', 'site', 'site_module:services', 'site.cache']],
        'site.portfolio.index' => ['GET', 'portfolio', ['web', 'site', 'site_module:portfolio', 'site.cache']],
        'site.portfolio.show' => ['GET', 'portfolio/{portfolioItem}', ['web', 'site', 'site_module:portfolio', 'site.cache']],
        'site.team.index' => ['GET', 'team', ['web', 'site', 'site_module:team', 'site.cache']],
        'site.blog.index' => ['GET', 'blog', ['web', 'site', 'site_module:blog_posts', 'site.cache']],
        'site.blog.category' => ['GET', 'blog/category/{blogCategory}', ['web', 'site', 'site_module:blog_posts', 'site.cache']],
        'site.blog.tag' => ['GET', 'blog/tag/{blogTag}', ['web', 'site', 'site_module:blog_posts', 'site.cache']],
        'site.blog.show' => ['GET', 'blog/{blogPost}', ['web', 'site', 'site_module:blog_posts']],
        'site.blog.preview' => ['GET', 'preview/blog/{blogPost}', ['web', 'site', 'auth', 'active']],
        'site.careers.index' => ['GET', 'careers', ['web', 'site', 'site_module:jobs', 'site.cache']],
        'site.careers.show' => ['GET', 'careers/{jobOpening}', ['web', 'site', 'site_module:jobs']],
        'site.careers.apply' => ['POST', 'careers/{jobOpening}/apply', ['web', 'site', 'site_module:jobs', 'throttle:public-apply']],
        'site.contact.index' => ['GET', 'contact', ['web', 'site']],
        'site.contact.store' => ['POST', 'contact', ['web', 'site', 'throttle:public-contact']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_phase_4_registers_its_168_route_names_once_each(): void
    {
        $prefixes = array_values($this->marketingRoutePrefixes());
        $admin = [];
        $public = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (Str::startsWith($name, $prefixes)) {
                $admin[] = $name;
            }

            if (array_key_exists($name, self::PUBLIC_ROUTES)) {
                $public[] = $name;
            }
        }

        $this->assertCount(153, $admin, 'Integration §5.1 registers 153 phase-04 admin routes.');
        $this->assertSame($admin, array_values(array_unique($admin)), 'No phase-04 admin route name is registered twice.');
        $this->assertCount(15, $public, 'Integration §5.2 registers 15 phase-04 public routes.');
        $this->assertSame($public, array_values(array_unique($public)), 'No phase-04 public route name is registered twice.');
        $this->assertCount(168, array_merge($admin, $public));

        sort($admin);
        $table = array_keys($this->marketingRouteTable());
        sort($table);

        $this->assertSame($table, $admin, 'The registered admin routes are exactly the §7.2 route table.');
    }

    public function test_every_marketing_admin_route_matches_the_contract_and_carries_its_gates(): void
    {
        $permissions = PermissionRegistry::permissionNames();
        $modules = PermissionRegistry::modules();

        foreach ($this->marketingRouteTable() as $name => $entry) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertInstanceOf(RoutingRoute::class, $route, sprintf('Route %s is not registered (phase-04 §7.2).', $name));
            $this->assertContains($entry['method'], $route->methods(), sprintf('%s must answer %s.', $name, $entry['method']));
            $this->assertSame($entry['uri'], $route->uri(), sprintf('%s must live at /%s.', $name, $entry['uri']));

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            foreach (['auth', 'active', 'panel:admin'] as $required) {
                $this->assertContains($required, $middleware, sprintf('%s must carry %s.', $name, $required));
            }

            $can = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')));
            $module = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'module:')));

            $this->assertSame(['can:'.$entry['permission']], $can, sprintf('%s must carry exactly can:%s.', $name, $entry['permission']));
            $this->assertContains($entry['permission'], $permissions, sprintf('%s is not declared by PermissionRegistry.', $entry['permission']));

            $slug = explode('.', $entry['permission'], 2)[0];

            $this->assertSame(['module:'.$slug], $module, sprintf('%s must carry exactly module:%s.', $name, $slug));
            $this->assertArrayHasKey($slug, $modules);
            $this->assertFalse((bool) $modules[$slug]['is_core'], sprintf('%s must be a non-core module so it can be switched off (§4).', $slug));
            $this->assertStringStartsWith($this->marketingRoutePrefixes()[$slug], $name, sprintf('%s is guarded by the %s module but named for another.', $name, $slug));

            preg_match_all('/\{(\w+)\??\}/', $route->uri(), $parameters);

            foreach ($parameters[1] as $parameter) {
                $this->assertSame('[0-9]+', $route->wheres[$parameter] ?? null, sprintf('%s: {%s} must be whereNumber so a literal segment is never read as an id.', $name, $parameter));
            }
        }

        // R-2: both review queues open with `view`, never `view_any`, so a reviewer reaches its own slice.
        $this->assertContains('can:contact_inquiries.view', Route::getRoutes()->getByName('admin.contact-inquiries.index')->gatherMiddleware());
        $this->assertContains('can:job_applications.view', Route::getRoutes()->getByName('admin.job-applications.index')->gatherMiddleware());
    }

    public function test_the_marketing_public_routes_match_the_contract_and_never_carry_a_permission_gate(): void
    {
        // Building the HTTP kernel copies the middleware aliases onto the router, so they can be resolved.
        $this->app->make(HttpKernel::class);

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        foreach (self::PUBLIC_ROUTES as $name => [$method, $uri, $aliases]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertInstanceOf(RoutingRoute::class, $route, sprintf('Public route %s is not registered (phase-04 §7.1).', $name));
            $this->assertContains($method, $route->methods(), sprintf('%s must answer %s.', $name, $method));
            $this->assertSame($uri, $route->uri(), sprintf('%s must live at /%s.', $name, $uri));

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            $this->assertSame($aliases, $middleware, sprintf('%s must carry exactly %s (integration §5.2).', $name, implode(', ', $aliases)));

            foreach ($middleware as $m) {
                $this->assertFalse(str_starts_with($m, 'module:') || str_starts_with($m, 'can:'), sprintf('%s carries %s: no public route may (phase-03 INV-15).', $name, $m));
            }

            $resolved = collect($router->gatherRouteMiddleware($route))
                ->filter(static fn (mixed $m): bool => is_string($m))
                ->map(static fn (string $m): string => explode(':', $m, 2)[0]);

            $this->assertTrue($resolved->contains(EnsurePublicSiteAvailable::class), sprintf('%s must be behind the one public-site gate.', $name));

            if (collect($aliases)->contains(static fn (string $alias): bool => str_starts_with($alias, 'site_module:'))) {
                $this->assertTrue($resolved->contains(EnsureSiteModuleEnabled::class), sprintf('%s: site_module must resolve to the 404-ing module gate (D26).', $name));
            }

            // A per-request token or a view counter can never be served from the public cache.
            if (in_array($name, ['site.blog.show', 'site.blog.preview', 'site.careers.show', 'site.careers.apply', 'site.contact.index', 'site.contact.store'], true)) {
                $this->assertFalse($resolved->contains(CachePublicResponse::class), sprintf('%s must not be served from the public cache.', $name));
            }
        }

        // The blog preview authorises in the controller: signature first, then the policy (R-1).
        $this->assertSame('[0-9]+', Route::getRoutes()->getByName('site.blog.preview')->wheres['blogPost'] ?? null);
    }

    public function test_every_marketing_uri_round_trips_to_its_own_route_and_never_reaches_the_page_catch_all(): void
    {
        foreach ($this->marketingRouteTable() as $name => $entry) {
            $uri = '/'.preg_replace('/\{\w+\}/', '7', $entry['uri']);

            $this->assertSame($name, $this->matchRoute($entry['method'], $uri), sprintf('%s %s must resolve to %s.', $entry['method'], $uri, $name));
        }

        $public = [
            ['GET', '/services', 'site.services.index'],
            ['GET', '/services/web-development', 'site.services.show'],
            ['GET', '/portfolio', 'site.portfolio.index'],
            ['GET', '/portfolio/a-case-study', 'site.portfolio.show'],
            ['GET', '/team', 'site.team.index'],
            ['GET', '/blog', 'site.blog.index'],
            ['GET', '/blog/category/news', 'site.blog.category'],
            ['GET', '/blog/tag/laravel', 'site.blog.tag'],
            ['GET', '/blog/a-first-post', 'site.blog.show'],
            ['GET', '/preview/blog/7', 'site.blog.preview'],
            ['GET', '/careers', 'site.careers.index'],
            ['GET', '/careers/laravel-developer', 'site.careers.show'],
            ['POST', '/careers/laravel-developer/apply', 'site.careers.apply'],
            ['GET', '/contact', 'site.contact.index'],
            ['POST', '/contact', 'site.contact.store'],
        ];

        foreach ($public as [$method, $uri, $name]) {
            $this->assertSame($name, $this->matchRoute($method, $uri), sprintf('%s %s must resolve to %s, never the CMS catch-all.', $method, $uri, $name));
        }

        // A non-numeric id is never an admin record, and the literal segments stay literal.
        foreach (['/admin/services/abc', '/admin/services/abc/edit', '/admin/blog-posts/draft/edit', '/admin/contact-inquiries/new', '/admin/job-applications/cv', '/preview/blog/a-slug', '/admin/technologies/reorder'] as $uri) {
            $this->assertNotContains($this->matchRoute('GET', $uri), array_keys($this->marketingRouteTable()), sprintf('GET %s must not resolve to a phase-04 admin record route.', $uri));
        }

        $this->assertSame('admin.services.export', $this->matchRoute('GET', '/admin/services/export'));
        $this->assertSame('admin.job-applications.export', $this->matchRoute('GET', '/admin/job-applications/export'));
        $this->assertSame('admin.contact-inquiries.export', $this->matchRoute('GET', '/admin/contact-inquiries/export'));
        $this->assertSame('admin.blog-posts.calendar', $this->matchRoute('GET', '/admin/blog-posts/calendar'));
        $this->assertSame('admin.testimonials.bulk-approve', $this->matchRoute('POST', '/admin/testimonials/bulk-approve'));
        $this->assertSame('admin.contact-inquiries.route-pending', $this->matchRoute('POST', '/admin/contact-inquiries/route-pending'));
    }

    public function test_a_marketing_parameter_that_does_not_resolve_is_a_404(): void
    {
        $super = $this->createSuperAdmin();
        $missing = 999999999;

        $admin = [
            ['GET', route('admin.services.edit', ['service' => $missing])],
            ['GET', route('admin.portfolio.edit', ['item' => $missing])],
            ['GET', route('admin.team.edit', ['member' => $missing])],
            ['GET', route('admin.testimonials.edit', ['testimonial' => $missing])],
            ['GET', route('admin.student-reviews.edit', ['review' => $missing])],
            ['GET', route('admin.success-stories.edit', ['story' => $missing])],
            ['GET', route('admin.blog-posts.edit', ['post' => $missing])],
            ['GET', route('admin.jobs.edit', ['job' => $missing])],
            ['GET', route('admin.job-applications.show', ['application' => $missing])],
            ['GET', route('admin.contact-inquiries.show', ['inquiry' => $missing])],
            ['GET', route('admin.service-categories.show', ['term' => $missing])],
            ['GET', route('admin.technologies.edit', ['term' => $missing])],
            ['GET', route('admin.blog-tags.show', ['term' => $missing])],
        ];

        foreach ($admin as [$method, $url]) {
            foreach ([true, false] as $json) {
                $status = $this->actingAs($super)->sendCms($method, $url, [], $json)->getStatusCode();

                $this->assertSame(404, $status, sprintf('%s %s must be a 404 for a Super Admin; it answered %d.', $method, $url, $status));
            }
        }

        // A gallery image that is not a library asset at all.
        $item = $this->makePortfolioItem();
        $before = $this->marketingFingerprint();

        $this->actingAs($super)->sendCms('POST', route('admin.portfolio.images.cover', ['item' => $item->getKey(), 'image' => $missing]))->assertNotFound();
        $this->actingAs($super)->sendCms('DELETE', route('admin.portfolio.images.destroy', ['item' => $item->getKey(), 'image' => $missing]))->assertNotFound();

        $this->assertSame($before, $this->marketingFingerprint());

        // Public: an unknown slug is the site's own 404, and an unknown preview id is a 404 for staff too.
        $this->signOut();

        foreach (['/services/no-such-service', '/portfolio/no-such-project', '/blog/no-such-post', '/blog/category/no-such-category', '/blog/tag/no-such-tag', '/careers/no-such-opening'] as $uri) {
            $this->get($uri)->assertNotFound();
        }

        $this->actingAs($super)
            ->get(URL::temporarySignedRoute('site.blog.preview', Carbon::now()->addHour(), ['blogPost' => $missing]))
            ->assertNotFound();
    }

    /**
     * §10.2 `SidebarTest`: the fifteen phase-04 entries of the one Website group, in order, each agreeing
     * with its route; `contact_inquiries.view` alone shows exactly "Contact Inquiries"; every entry opens.
     */
    public function test_the_marketing_sidebar_entries_agree_with_their_routes_and_open_for_a_super_admin(): void
    {
        $entries = $this->marketingSidebarEntries();
        $found = [];

        foreach (Sidebar::tree('admin') as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $label = (string) ($item['label'] ?? '');

                if (! array_key_exists($label, $entries) || (string) ($item['route'] ?? '') !== $entries[$label]['route']) {
                    continue;
                }

                $this->assertSame('website', (string) $group['key'], sprintf('%s belongs to the one Website group (F-6.7).', $label));
                $this->assertSame([], $item['children'] ?? [], sprintf('%s is a flat item: a rendered parent has no URL (R-5).', $label));

                $found[] = $label;
                $route = Route::getRoutes()->getByName($entries[$label]['route']);

                $this->assertInstanceOf(RoutingRoute::class, $route, sprintf('The sidebar links to %s, which is not registered.', $entries[$label]['route']));
                $this->assertSame($entries[$label]['permission'], $item['permission'], sprintf('%s must state the permission its route checks.', $label));
                $this->assertSame($entries[$label]['module'], $item['module'], sprintf('%s must state its module.', $label));
                $this->assertContains('can:'.$item['permission'], $route->gatherMiddleware(), sprintf('The sidebar entry %s states %s; its route does not.', $label, $item['permission']));
                $this->assertContains('module:'.$item['module'], $route->gatherMiddleware(), sprintf('The sidebar entry %s states module %s; its route does not.', $label, $item['module']));
            }
        }

        $this->assertSame(array_keys($entries), $found, 'The fifteen phase-04 entries appear once each, in the §8 order.');

        $super = $this->createSuperAdmin();
        $labels = $this->cmsSidebarLabels($super);
        $seo = array_search('SEO', $labels, true);

        $this->assertIsInt($seo, 'Phase 3\'s SEO entry is still there.');
        $this->assertSame(array_keys($entries), array_slice($labels, $seo + 1, 15), 'Phase 4 appends its entries after Phase 3\'s, in order.');

        foreach ($entries as $label => $entry) {
            $this->actingAs($super)->get(route($entry['route']))->assertOk();
        }

        $this->assertSame(['Contact Inquiries'], $this->cmsSidebarLabels($this->createUserWithPermissions(['contact_inquiries.view'])), 'contact_inquiries.view alone shows exactly the queue it opens (R-2).');
        $this->assertSame(['Job Applications'], $this->cmsSidebarLabels($this->createUserWithPermissions(['job_applications.view'])), 'job_applications.view alone shows exactly the queue it opens (R-2).');
        $this->assertSame(['Blog Posts', 'Blog Categories', 'Blog Tags'], $this->cmsSidebarLabels($this->createUserWithPermissions(['blog_posts.view_any', 'blog_categories.view_any', 'blog_tags.view_any'])));

        $this->assertSame(
            [],
            array_values(array_intersect(array_keys($entries), $this->cmsSidebarLabels($this->createUserWithRole('Support Agent')))),
            'The Support Agent holds no phase-04 permission and sees no phase-04 entry (§9.1).',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function matchRoute(string $method, string $uri): string|int
    {
        try {
            return Route::getRoutes()->match(Request::create($uri, $method))->getName() ?? '(unnamed)';
        } catch (NotFoundHttpException) {
            return 404;
        } catch (MethodNotAllowedHttpException) {
            return 405;
        }
    }
}
