<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http;

use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Http\Middleware\ResolvePreviewMode;
use App\Services\Cms\PageService;
use App\Support\PermissionRegistry;
use App\Support\SettingsRepository;
use App\Support\Sidebar;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The route contract of phase-03 §7: names, methods, URIs and the gates each route carries.
 *
 *   · every admin CMS route (§7.1-§7.5) exists exactly as the contract writes it, behind `auth`,
 *     `active`, `panel:admin`, exactly one `module:` and exactly one `can:` of that module, whose
 *     permission PermissionRegistry declares; the throttled routes (three from §7, plus media
 *     regeneration from review round 2) carry their limits;
 *   · the public routes (§7.6) exist, and **no public route carries `module:` or `can:`** (INV-15); the
 *     `site` gate is on every public page but never on robots.txt ([D-W3-13]);
 *   · the `/{slug}` catch-all never shadows a panel, an auth screen, a reserved first segment or a later
 *     phase's route, and a parameter that does not resolve is a 404;
 *   · every Website sidebar entry points at a registered route with the same permission and module.
 */
final class CmsRouteContractTest extends TestCase
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

    public function test_every_admin_cms_route_matches_the_contract_and_carries_its_gates(): void
    {
        $permissions = PermissionRegistry::permissionNames();
        $modules = PermissionRegistry::modules();

        foreach ($this->cmsRouteTable() as $name => $entry) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertInstanceOf(RoutingRoute::class, $route, sprintf('Route %s is not registered (phase-03 §7).', $name));
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
            $this->assertFalse((bool) $modules[$slug]['is_core'], sprintf('%s must be a non-core module so it can be switched off (§4.1).', $slug));
        }

        $throttles = [
            'admin.website.cache.flush' => 'throttle:6,1',
            'admin.website.seo.sitemap.regenerate' => 'throttle:6,1',
            'admin.website.media.store' => 'throttle:60,1',
            // Review round 2: inline derivative regeneration is CPU- and memory-heavy.
            'admin.website.media.regenerate' => 'throttle:6,1',
        ];

        foreach ($throttles as $name => $limit) {
            $matching = array_filter(
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                static fn (mixed $m): bool => is_string($m) && ($m === $limit || str_starts_with($m, $limit.',')),
            );

            $this->assertNotEmpty($matching, sprintf('%s must carry %s (phase-03 §7).', $name, $limit));
        }
    }

    public function test_the_public_routes_match_the_contract_and_never_carry_a_module_or_permission_gate(): void
    {
        $expected = [
            'site.home' => ['GET', '/', ['site', 'site.preview', 'site.cache']],
            'site.robots' => ['GET', 'robots.txt', []],
            'site.sitemap' => ['GET', 'sitemap.xml', ['site.cache']],
            'site.sitemap.chunk' => ['GET', 'sitemap-{index}.xml', ['site.cache']],
            'site.preview.page' => ['GET', 'preview/page/{page}', ['site.preview']],
            'site.preview.section' => ['GET', 'preview/section/{section}', ['site.preview']],
            'site.page' => ['GET', '{slug}', ['site', 'site.preview', 'site.cache']],
        ];

        // Building the HTTP kernel copies the middleware aliases onto the router, so they can be resolved.
        $this->app->make(HttpKernel::class);

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        foreach ($expected as $name => [$method, $uri, $aliases]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertInstanceOf(RoutingRoute::class, $route, sprintf('Public route %s is not registered (phase-03 §7.6).', $name));
            $this->assertContains($method, $route->methods());
            $this->assertNotContains('POST', $route->methods(), sprintf('%s must not accept a write.', $name));
            $this->assertSame($uri, $route->uri());

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            foreach ($aliases as $alias) {
                $this->assertContains($alias, $middleware, sprintf('%s must carry %s (phase-03 §7.6).', $name, $alias));
            }

            foreach ($middleware as $m) {
                $this->assertFalse(str_starts_with($m, 'module:') || str_starts_with($m, 'can:'), sprintf('%s carries %s: no public route may (INV-15).', $name, $m));
            }

            $resolved = collect($router->gatherRouteMiddleware($route))
                ->filter(static fn (mixed $m): bool => is_string($m))
                ->map(static fn (string $m): string => explode(':', $m, 2)[0]);

            if ($name === 'site.robots') {
                $this->assertFalse($resolved->contains(EnsurePublicSiteAvailable::class), 'robots.txt must answer while the site is closed ([D-W3-13]).');
                $this->assertFalse($resolved->contains(CachePublicResponse::class));
                $this->assertFalse($resolved->contains(ResolvePreviewMode::class));
            }

            if (in_array('site', $aliases, true)) {
                $this->assertTrue($resolved->contains(EnsurePublicSiteAvailable::class), sprintf('%s must be behind the one public-site gate.', $name));
            }
        }

        // Every other public GET route of the application carries no CMS gate either.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'site.')) {
                continue;
            }

            foreach (array_filter($route->gatherMiddleware(), 'is_string') as $m) {
                $this->assertFalse(str_starts_with($m, 'can:'), sprintf('%s carries %s: no public route may (INV-15).', $name, $m));
            }
        }
    }

    public function test_the_page_catch_all_never_shadows_a_panel_an_auth_screen_or_a_reserved_segment(): void
    {
        $expectations = [
            ['GET', '/', 'site.home'],
            ['GET', '/robots.txt', 'site.robots'],
            ['GET', '/sitemap.xml', 'site.sitemap'],
            ['GET', '/sitemap-2.xml', 'site.sitemap.chunk'],
            ['GET', '/preview/page/5', 'site.preview.page'],
            ['GET', '/preview/section/5', 'site.preview.section'],
            ['GET', '/privacy-policy', 'site.page'],
            ['GET', '/a-new-page-2', 'site.page'],
            ['GET', '/admin', 'admin.dashboard'],
            ['GET', '/login', 'login'],
            ['GET', '/student', 'student.dashboard'],
            ['GET', '/teacher', 'teacher.dashboard'],
            ['GET', '/client', 'client.dashboard'],
            ['GET', '/collaborator', 'collaborator.dashboard'],
            ['GET', '/admin/website/sections/home', 'admin.website.sections.index'],
            ['POST', '/admin/website/sections/reorder', 'admin.website.sections.reorder'],
            ['GET', '/admin/website/pages/export', 'admin.website.pages.export'],
            ['GET', '/admin/website/pages/create', 'admin.website.pages.create'],
            ['GET', '/admin/website/seo/edit', 'admin.website.seo.edit'],
            ['POST', '/admin/website/faqs/reorder', 'admin.website.faqs.reorder'],
            ['POST', '/admin/website/faq-categories/reorder', 'admin.website.faq-categories.reorder'],
            ['GET', '/admin/website/sections/nowhere', 404],
            ['GET', '/admin/website/sections/abc/edit', 404],
            ['GET', '/sitemap-abc.xml', 404],
            ['GET', '/Upper-Case', 404],
            ['GET', '/two/segments', 404],
            ['GET', '/-leading-hyphen', 404],
            ['POST', '/register', 404],
        ];

        // No reserved first segment (§6.4: the panels, the auth screens and every later phase's public
        // area) is ever claimed by a CMS page.
        foreach (app(PageService::class)->reservedSlugs() as $reserved) {
            if (preg_match('/^[a-z0-9-]+$/', $reserved) === 1) {
                $expectations[] = ['GET', '/'.$reserved, 'not site.page'];
            }
        }

        foreach ($expectations as [$method, $uri, $want]) {
            $got = $this->matchRoute($method, $uri);

            if ($want === 'not site.page') {
                $this->assertNotSame('site.page', $got, sprintf('%s %s must never reach the page catch-all.', $method, $uri));

                continue;
            }

            $this->assertSame($want, $got, sprintf('%s %s must resolve to %s.', $method, $uri, var_export($want, true)));
        }

        // Over HTTP: a later phase's first segment that no route serves yet is a plain 404, never a CMS page.
        foreach (['courses', 'services', 'portfolio', 'blog', 'careers', 'contact', 'admission', 'certificate', 'team', 'verify'] as $segment) {
            $this->assertContains($segment, app(PageService::class)->reservedSlugs(), sprintf('"%s" must be a reserved first segment (§6.4, integration A.1).', $segment));

            if ($this->matchRoute('GET', '/'.$segment) === 404) {
                $this->get('/'.$segment)->assertNotFound();
            }
        }

        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_route_parameters_that_do_not_resolve_are_a_404(): void
    {
        $super = $this->createSuperAdmin();
        $hero = $this->cmsSection('hero');

        $this->actingAs($super)->get(url('/admin/website/sections/nowhere'))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.sections.edit', ['section' => 999999999]))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.pages.edit', ['page' => 999999999]))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.menus.show', ['menu' => 999999999]))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.cta-blocks.edit', ['ctaBlock' => 999999999]))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.media.show', ['asset' => 999999999]))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.faqs.index', ['category' => '999999999']))->assertNotFound();

        // A section page of a page that is not composed of sections, or of no page at all.
        $contentPage = $this->makeCmsPage();
        $this->actingAs($super)->get(route('admin.website.sections.index', ['placement' => 'page']))->assertNotFound();
        $this->actingAs($super)->get(route('admin.website.sections.index', ['placement' => 'page', 'page_id' => $contentPage->getKey()]))->assertNotFound();

        // A repeater group the section type does not declare.
        $before = $this->cmsFingerprint();
        $this->actingAs($super)
            ->sendCms('POST', route('admin.website.sections.items.reorder', ['section' => $hero->getKey(), 'group' => 'no_such_group']), ['order' => [1]])
            ->assertNotFound();
        $this->assertSame($before, $this->cmsFingerprint());

        // A trashed page is found only by the restore route (withTrashed binding), never by the editor.
        $trashed = $this->makeTrashedCmsPage();
        $this->actingAs($super)->get(route('admin.website.pages.edit', $trashed))->assertNotFound();
        $this->actingAs($super)->sendCms('POST', route('admin.website.pages.restore', $trashed))->assertOk();
        $this->assertNotSoftDeleted('pages', ['id' => $trashed->getKey()]);

        // Preview ids that do not exist are a 404 for staff too.
        $this->actingAs($super)->get(route('site.preview.page', ['page' => 999999999]))->assertNotFound();
        $this->actingAs($super)->get(route('site.preview.section', ['section' => 999999999]))->assertNotFound();
    }

    public function test_every_website_sidebar_entry_agrees_with_its_route(): void
    {
        $found = 0;

        foreach (Sidebar::tree('admin') as $group) {
            foreach ($this->flattenSidebar($group['items'] ?? []) as $item) {
                $routeName = (string) ($item['route'] ?? '');

                if (! str_starts_with($routeName, 'admin.website.')) {
                    continue;
                }

                $found++;
                $route = Route::getRoutes()->getByName($routeName);

                $this->assertInstanceOf(RoutingRoute::class, $route, sprintf('The sidebar links to %s, which is not registered.', $routeName));

                $middleware = $route->gatherMiddleware();

                $this->assertContains('can:'.$item['permission'], $middleware, sprintf('The sidebar entry %s states %s; its route does not.', $item['label'], $item['permission']));
                $this->assertContains('module:'.$item['module'], $middleware, sprintf('The sidebar entry %s states module %s; its route does not.', $item['label'], $item['module']));
            }
        }

        $this->assertSame(9, $found, 'phase-03 §8 declares nine Website CMS sidebar entries.');

        $labels = $this->cmsSidebarLabels($this->createSuperAdmin());

        foreach ($this->cmsModuleSidebarLabels() as $moduleLabels) {
            foreach ($moduleLabels as $label) {
                $this->assertContains($label, $labels, sprintf('A Super Admin must see the %s entry.', $label));
            }
        }

        $this->assertSame(
            ['Website Overview', 'Sections'],
            $this->cmsSidebarLabels($this->createUserWithPermissions(['website_sections.view_any'])),
            'website_sections.view_any alone shows exactly the two entries it opens.',
        );

        $cmsLabels = array_merge(...array_values($this->cmsModuleSidebarLabels()));

        $this->assertSame(
            [],
            array_values(array_intersect($cmsLabels, $this->cmsSidebarLabels($this->createUserWithRole('HR')))),
            'HR holds no CMS permission and sees no Website CMS entry.',
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

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function flattenSidebar(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;

            foreach ($this->flattenSidebar($item['children'] ?? []) as $child) {
                $flat[] = $child;
            }
        }

        return $flat;
    }
}
