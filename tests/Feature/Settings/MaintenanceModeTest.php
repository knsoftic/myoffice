<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Maintenance": `maintenance_mode` on blocks the public site but never `/admin`;
 * `public_site_enabled` off returns the holding page.
 *
 * The switches are flipped on the Maintenance screen itself, and the administrator who flipped
 * them must be able to get back to that screen and flip them off again — a gate that can lock its
 * own operator out is the failure this test exists to rule out.
 */
final class MaintenanceModeTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    #[Test]
    public function the_public_site_is_open_by_default(): void
    {
        $this->get('/')->assertOk();
    }

    #[Test]
    public function maintenance_mode_closes_the_public_site_with_a_503_holding_page(): void
    {
        $admin = $this->createSuperAdmin();

        $this->switchOn($admin, 'maintenance_mode', ['maintenance_message' => 'Upgrading the enrolment system — back by noon.']);

        $this->signOut();

        $this->get('/')
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertSee('Scheduled maintenance', false)
            ->assertSee('Upgrading the enrolment system — back by noon.', false)
            ->assertSee('noindex', false);
    }

    #[Test]
    public function maintenance_mode_never_blocks_the_admin_panel_or_the_way_back_to_the_switch(): void
    {
        $admin = $this->createSuperAdmin();

        $this->switchOn($admin, 'maintenance_mode');

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/settings/maintenance')->assertOk();
        $this->actingAs($admin)->get('/admin/modules')->assertOk();

        // …and the administrator can switch it off from there.
        $this->actingAs($admin)
            ->put('/admin/settings/maintenance', $this->browserPayload($admin, 'maintenance', uncheck: ['maintenance_mode']))
            ->assertSessionHasNoErrors();

        $this->signOut();

        $this->get('/')->assertOk();
    }

    #[Test]
    public function maintenance_mode_never_blocks_signing_in(): void
    {
        $admin = $this->createSuperAdmin();

        $this->switchOn($admin, 'maintenance_mode');
        $this->signOut();

        // An administrator whose session expired mid-maintenance must still be able to sign in
        // and turn it off.
        $this->get('/login')->assertOk();
    }

    #[Test]
    public function maintenance_mode_never_blocks_a_portal(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createUserWithRole('Student');

        $this->switchOn($admin, 'maintenance_mode');

        $this->actingAs($student)->get('/student')->assertOk();
    }

    #[Test]
    public function switching_the_public_site_off_returns_the_holding_page(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/maintenance', $this->browserPayload($admin, 'maintenance', uncheck: ['public_site_enabled']))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->freshSetting('maintenance.public_site_enabled', true));

        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->signOut();

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('This website is currently unavailable.', false);
    }

    #[Test]
    public function every_public_page_route_carries_the_public_site_gate(): void
    {
        $ungated = $this->ungatedPublicRoutes($this->applicationRouter());

        $this->assertSame(
            [],
            $ungated,
            'These public GET routes ignore maintenance mode: '.implode(', ', $ungated)
        );
    }

    /**
     * The scanner above is proven against a stand-in router this test defines, so its teeth never
     * depend on which public routes a later phase happens to register: on a Phase 2 tree the real
     * router carries a single public page, and a scan that could not tell a gated route from an
     * ungated one would pass there just the same.
     */
    #[Test]
    public function the_public_route_scan_catches_an_ungated_page_and_recognises_the_gate_in_every_form(): void
    {
        $real = $this->applicationRouter();
        $router = new Router($this->app->make('events'), $this->app);

        foreach ($real->getMiddleware() as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }

        foreach ($real->getMiddlewareGroups() as $group => $members) {
            $router->middlewareGroup($group, $members);
        }

        $router->middlewareGroup('phase2-standin-site', ['public_site']);

        $page = static fn (): string => 'stand-in';

        // Gated: by the alias, by the class itself, and through a group that carries it.
        $router->get('/standin-gated-by-alias', $page)->middleware('public_site');
        $router->get('/standin-gated-by-class', $page)->middleware(EnsurePublicSiteAvailable::class);
        $router->get('/standin-gated-by-group', $page)->middleware('phase2-standin-site');

        // Not the public site: a panel page, a sign-in page, a form post, framework plumbing.
        $router->get('/standin-panel', $page)->middleware('auth');
        $router->get('/standin-sign-in', $page)->middleware('guest');
        $router->post('/standin-form', $page);
        $router->get('/up', $page);

        // Ungated public pages — the failures the scan exists to catch, including a gate that was
        // declared and then excluded again.
        $router->get('/standin-open', $page);
        $router->get('/standin-excluded', $page)->middleware('phase2-standin-site')->withoutMiddleware('public_site');

        $this->assertSame(['/standin-open', '/standin-excluded'], $this->ungatedPublicRoutes($router));
    }

    /**
     * The application's router with its middleware aliases and groups in place.
     *
     * The HTTP kernel copies them onto the router when it is constructed, and a test that has not
     * sent a request yet has never constructed it — without this, no alias resolves to its class.
     */
    private function applicationRouter(): Router
    {
        $this->app->make(HttpKernel::class);

        return $this->app->make(Router::class);
    }

    /**
     * Public GET routes whose resolved middleware stack does not run `EnsurePublicSiteAvailable`.
     *
     * The gate is recognised by the class the stack resolves to — through an alias, a middleware
     * group or the class name — never by one literal alias, and a route that excludes it again is
     * reported. That is the same gate seen more accurately, not a looser rule.
     *
     * @return list<string>
     */
    private function ungatedPublicRoutes(Router $router): array
    {
        $ungated = [];

        foreach ($router->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            $middleware = $route->gatherMiddleware();

            $isPanelOrAuth = collect($middleware)->contains(
                static fn (mixed $name): bool => is_string($name) && ($name === 'auth' || $name === 'guest' || str_starts_with($name, 'auth:') || str_starts_with($name, 'signed'))
            );

            if ($isPanelOrAuth || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Framework plumbing that is not "the public site".
            // phase-03 §6.5 [D-W3-13], FT-47: robots.txt must answer while the site is closed.
            if ($uri === '/up' || $uri === '/robots.txt' || str_starts_with($uri, '/storage') || str_starts_with($uri, '/_') || str_starts_with($uri, '/sanctum')) {
                continue;
            }

            $gated = collect($router->gatherRouteMiddleware($route))->contains(
                static fn (mixed $resolved): bool => is_string($resolved)
                    && explode(':', $resolved, 2)[0] === EnsurePublicSiteAvailable::class
            );

            if (! $gated) {
                $ungated[] = $uri;
            }
        }

        return $ungated;
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function switchOn(User $admin, string $key, array $overrides = []): void
    {
        $this->actingAs($admin)
            ->put('/admin/settings/maintenance', $this->browserPayload($admin, 'maintenance', $overrides, check: [$key]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->freshSetting('maintenance.'.$key, false));
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
