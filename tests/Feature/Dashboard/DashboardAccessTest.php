<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\Widget;
use App\Models\Permission;
use App\Models\User;
use App\Support\DashboardRegistry;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-02 §6 "Dashboard" (filtered by permission and by module state) and the dashboard half of
 * "Authorization": no permission is a 403, and a widget a user may not see never reaches the page —
 * neither its card nor its figures — and cannot be pulled through the JSON endpoint by editing the URL.
 *
 * Every Phase 2 widget belongs to a core module, which can never be switched off, so module filtering is
 * proved with a probe widget registered on the non-core `leads` module: exactly how a later phase adds a
 * card.
 */
final class DashboardAccessTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const PHASE2_KEYS = [
        'users_by_status', 'roles_overview', 'modules_enabled', 'logins_today', 'failed_logins',
        'login_trend_chart', 'recent_activity', 'recent_logins', 'system_health', 'storage_usage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        DashboardRegistry::reset();
    }

    protected function tearDown(): void
    {
        DashboardRegistry::reset();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization matrix
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->getJson('/admin/dashboard/widget/users_by_status')->assertUnauthorized();
        $this->putJson('/admin/dashboard/layout', ['hidden' => []])->assertUnauthorized();
    }

    #[Test]
    public function no_dashboard_permission_is_refused_the_page_the_widget_json_and_the_layout(): void
    {
        $user = $this->createUserWithPermissions(['users.view_any']);

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->getJson('/admin/dashboard/widget/users_by_status')->assertForbidden();
        $this->actingAs($user)->putJson('/admin/dashboard/layout', ['order' => ['users_by_status']])->assertForbidden();

        $this->assertNull($user->fresh()->preference('dashboard.widget_order'));
    }

    #[Test]
    public function the_dashboard_permission_alone_opens_an_empty_grid_with_no_figures(): void
    {
        $user = $this->createUserWithPermissions(['dashboard.view']);

        $this->assertSame([], DashboardRegistry::for($user)->keys()->all());

        $html = (string) $this->actingAs($user)->get('/admin')->assertOk()->getContent();

        foreach (self::PHASE2_KEYS as $key) {
            $this->assertStringNotContainsString('data-widget-key="'.$key.'"', $html, $key.' reached a page whose viewer holds no widget permission.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permission filtering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function each_viewer_gets_exactly_the_cards_their_permissions_allow(): void
    {
        $cases = [
            'users only' => [['dashboard.view', 'users.view_any'], ['users_by_status']],
            'roles only' => [['dashboard.view', 'roles.view_any'], ['roles_overview']],
            'login history' => [['dashboard.view', 'login_history.view_logs'], ['logins_today', 'failed_logins', 'login_trend_chart', 'recent_logins']],
            'activity log' => [['dashboard.view', 'activity_log.view_logs'], ['recent_activity']],
            'modules' => [['dashboard.view', 'modules.view_any'], ['modules_enabled']],
        ];

        foreach ($cases as $label => [$permissions, $expected]) {
            $user = $this->createUserWithPermissions($permissions);

            $this->assertEqualsCanonicalizing($expected, DashboardRegistry::for($user)->keys()->all(), $label.': registry');

            $html = (string) $this->actingAs($user)->get('/admin')->assertOk()->getContent();

            $this->assertEqualsCanonicalizing($expected, $this->renderedKeys($html), $label.': rendered cards');
        }
    }

    #[Test]
    public function a_super_admin_sees_every_phase_two_card(): void
    {
        $admin = $this->createSuperAdmin();

        $html = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

        foreach (self::PHASE2_KEYS as $key) {
            $this->assertContains($key, $this->renderedKeys($html), $key.' is missing for a Super Admin.');
        }
    }

    #[Test]
    public function a_card_the_viewer_may_not_see_leaves_no_trace_of_its_figures(): void
    {
        $admin = $this->createSuperAdmin(['name' => 'Zubair Visible-Only-To-Activity']);

        // An activity row whose description only the activity card would print.
        activity('settings')->causedBy($admin)->log('Secret-activity-description-7c1e');

        $viewer = $this->createUserWithPermissions(['dashboard.view', 'users.view_any']);

        $this->actingAs($viewer)
            ->get('/admin?eager=1')
            ->assertOk()
            ->assertDontSee('Secret-activity-description-7c1e', false);

        $this->actingAs($admin)
            ->get('/admin?eager=1')
            ->assertOk()
            ->assertSee('Secret-activity-description-7c1e', false);
    }

    /*
    |--------------------------------------------------------------------------
    | The widget endpoint re-checks permission
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_widget_endpoint_refuses_a_card_the_viewer_may_not_see(): void
    {
        $viewer = $this->createUserWithPermissions(['dashboard.view', 'users.view_any']);

        foreach (['recent_activity', 'logins_today', 'system_health', 'modules_enabled'] as $key) {
            $response = $this->actingAs($viewer)->getJson('/admin/dashboard/widget/'.$key);

            $response->assertNotFound();
            $this->assertNull($response->json('data'), $key.' leaked its data.');
            $this->assertNull($response->json('html'), $key.' leaked its markup.');
        }

        $this->actingAs($viewer)
            ->getJson('/admin/dashboard/widget/users_by_status')
            ->assertOk()
            ->assertJsonPath('key', 'users_by_status')
            ->assertJsonStructure(['key', 'widget', 'range', 'data', 'html']);

        $this->actingAs($viewer)->getJson('/admin/dashboard/widget/not_a_widget')->assertNotFound();
    }

    #[Test]
    public function losing_a_permission_closes_the_widget_endpoint_on_the_very_next_request(): void
    {
        $viewer = $this->createUserWithPermissions(['dashboard.view', 'users.view_any']);

        $this->actingAs($viewer)->getJson('/admin/dashboard/widget/users_by_status')->assertOk();

        $viewer->roles()->first()->revokePermissionTo('users.view_any');
        $this->forgetPermissionCache();

        $this->actingAs($viewer->fresh())->getJson('/admin/dashboard/widget/users_by_status')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Module filtering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_card_on_a_disabled_module_disappears_for_everyone_and_returns_when_it_is_enabled(): void
    {
        $permission = (string) Permission::query()->where('module', 'leads')->orderBy('id')->value('name');
        $this->assertNotSame('', $permission, 'The leads module has seeded permissions.');

        DashboardRegistry::register($this->leadsProbe($permission));

        $admin = $this->createSuperAdmin();
        $holder = $this->createUserWithPermissions(['dashboard.view', $permission]);
        $stranger = $this->createUserWithPermissions(['dashboard.view']);

        // Enabled: the holder and the Super Admin see it, the stranger does not.
        $this->assertContains('phase2_leads_probe', DashboardRegistry::for($holder)->keys()->all());
        $this->assertContains('phase2_leads_probe', DashboardRegistry::for($admin)->keys()->all());
        $this->assertNotContains('phase2_leads_probe', DashboardRegistry::for($stranger)->keys()->all());

        $this->actingAs($holder)->getJson('/admin/dashboard/widget/phase2_leads_probe')->assertOk()->assertJsonPath('data.open_leads', 7);
        $this->actingAs($stranger)->getJson('/admin/dashboard/widget/phase2_leads_probe')->assertNotFound();
        $this->assertContains('phase2_leads_probe', $this->renderedKeys((string) $this->actingAs($holder)->get('/admin')->getContent()));

        // Disabled: gone for everyone, Super Admin included (D5).
        $this->switchModule('leads', false);

        foreach ([$holder, $admin] as $viewer) {
            $this->assertNotContains('phase2_leads_probe', DashboardRegistry::for($viewer)->keys()->all());
            $this->actingAs($viewer)->getJson('/admin/dashboard/widget/phase2_leads_probe')->assertNotFound();
            $this->assertNotContains('phase2_leads_probe', $this->renderedKeys((string) $this->actingAs($viewer)->get('/admin')->assertOk()->getContent()));
        }

        // Enabled again: back, with nothing lost.
        $this->switchModule('leads', true);

        $this->actingAs($holder)->getJson('/admin/dashboard/widget/phase2_leads_probe')->assertOk()->assertJsonPath('data.open_leads', 7);
    }

    /**
     * @return list<string>
     */
    private function renderedKeys(string $html): array
    {
        preg_match_all('/data-widget-key="([a-z0-9_]+)"/', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function leadsProbe(string $permission): Widget
    {
        return new class($permission) extends Widget
        {
            public function __construct(private readonly string $ability) {}

            public function key(): string
            {
                return 'phase2_leads_probe';
            }

            public function title(): string
            {
                return 'Open leads (probe)';
            }

            public function permission(): ?string
            {
                return $this->ability;
            }

            public function module(): ?string
            {
                return 'leads';
            }

            public function data(DateRange $range): array
            {
                return ['open_leads' => 7];
            }
        };
    }
}
