<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            $middleware = $route->gatherMiddleware();

            $isPanelOrAuth = collect($middleware)->contains(
                static fn (mixed $name): bool => is_string($name) && ($name === 'auth' || $name === 'guest' || str_starts_with($name, 'auth:') || str_starts_with($name, 'signed'))
            );

            if ($isPanelOrAuth || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Framework plumbing that is not "the public site".
            if ($uri === '/up' || str_starts_with($uri, '/storage') || str_starts_with($uri, '/_') || str_starts_with($uri, '/sanctum')) {
                continue;
            }

            if (! in_array('public_site', $middleware, true)) {
                $ungated[] = $uri;
            }
        }

        $this->assertSame(
            [],
            $ungated,
            'These public GET routes ignore maintenance mode: '.implode(', ', $ungated)
        );
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
