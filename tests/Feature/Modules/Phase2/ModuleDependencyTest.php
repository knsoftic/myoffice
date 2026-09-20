<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Phase2;

use App\Models\Module;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-02 §6 "Module dependency": disabling a module that an enabled module depends on is blocked
 * and names the dependents; cascade works only when explicitly requested; core modules cannot be
 * disabled.
 *
 * The graph used is the real seeded one (`ModuleSeeder` projects `PermissionRegistry::dependencyGraph()`
 * onto `modules.depends_on`): `projects` → `clients`, `invoices` → `clients`, `tasks` →
 * `projects`, `time_tracking` → `tasks`, `project_milestones` → `projects`, `payments` →
 * `projects`.
 */
final class ModuleDependencyTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private ModuleService $modules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->modules = app(ModuleService::class);
    }

    #[Test]
    public function the_seeded_dependency_graph_is_what_the_service_reads(): void
    {
        $this->assertSame(['clients'], $this->module('projects')->depends_on);
        $this->assertEqualsCanonicalizing(['projects', 'invoices'], $this->modules->dependents('clients'));
        $this->assertEqualsCanonicalizing(['project_milestones', 'tasks', 'payments', 'project_payments'], $this->modules->dependents('projects'));
        $this->assertSame(['tasks'], $this->modules->dependencies('time_tracking'));
        $this->assertSame([], $this->modules->missingDependencies('projects'), 'Everything is enabled on a fresh install.');
    }

    #[Test]
    public function missing_dependencies_are_the_ones_switched_off(): void
    {
        $this->switchModule('students', false);

        $this->assertSame(['students'], $this->modules->missingDependencies('admissions'));
        $this->assertSame([], $this->modules->missingDependencies('courses'));
    }

    #[Test]
    public function the_service_refuses_to_disable_a_module_with_enabled_dependents_and_names_them(): void
    {
        $clients = $this->module('clients');

        try {
            $this->modules->toggle($clients, false, 'Shutting the CRM');
            $this->fail('Disabling clients while projects and invoices depend on it must be refused.');
        } catch (ActionNotAllowedException $exception) {
            $this->assertStringContainsString((string) $this->module('projects')->name, $exception->getMessage());
            $this->assertStringContainsString((string) $this->module('invoices')->name, $exception->getMessage());
        }

        $this->assertTrue((bool) $clients->fresh()->is_enabled);
        $this->assertTrue((bool) $this->module('projects')->is_enabled);
    }

    #[Test]
    public function the_toggle_endpoint_refuses_the_same_disable_and_says_who_is_blocking(): void
    {
        $admin = $this->createSuperAdmin();
        $clients = $this->module('clients');

        $this->actingAs($admin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$clients->getKey().'/toggle', ['enabled' => false, 'reason' => 'CRM retired'])
            ->assertRedirect('/admin/modules')
            ->assertSessionHas('toast', function (array $toast): bool {
                return $toast['type'] === 'error'
                    && str_contains($toast['message'], (string) $this->module('projects')->name)
                    && str_contains($toast['message'], (string) $this->module('invoices')->name);
            });

        $this->assertTrue((bool) $clients->fresh()->is_enabled);
    }

    #[Test]
    public function cascade_is_never_implied_by_a_falsy_or_missing_flag(): void
    {
        $admin = $this->createSuperAdmin();
        $clients = $this->module('clients');

        foreach ([[], ['cascade' => '0'], ['cascade' => 'false'], ['cascade' => ''], ['cascade' => 0]] as $extra) {
            $this->actingAs($admin)
                ->post('/admin/modules/'.$clients->getKey().'/toggle', ['enabled' => false, 'reason' => 'no cascade'] + $extra)
                ->assertRedirect();

            $this->assertTrue((bool) $clients->fresh()->is_enabled, 'cascade='.json_encode($extra).' must not take the module down.');
            $this->assertTrue((bool) $this->module('projects')->is_enabled);
        }
    }

    #[Test]
    public function an_explicit_cascade_takes_every_enabled_dependent_down_deepest_first(): void
    {
        $admin = $this->createSuperAdmin();

        $this->assertCascadeGoesDeepestFirst('clients', ['time_tracking', 'tasks', 'payment_reversals', 'payments', 'project_payments', 'project_milestones', 'projects', 'invoices']);

        $this->actingAs($admin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$this->module('clients')->getKey().'/toggle', ['enabled' => false, 'reason' => 'CRM retired', 'cascade' => true])
            ->assertRedirect('/admin/modules')
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'warning' && str_contains($toast['message'], 'no data was deleted'));

        foreach (['clients', 'projects', 'invoices', 'tasks', 'time_tracking', 'project_milestones', 'payments', 'project_payments', 'payment_reversals'] as $slug) {
            $this->assertFalse((bool) $this->module($slug)->is_enabled, $slug.' must be off after the cascade.');
        }

        $this->assertTrue((bool) $this->module('employees')->is_enabled, 'A module outside the chain is untouched.');

        $cascaded = $this->module('time_tracking');
        $this->assertStringStartsWith('Cascaded from clients:', (string) $cascaded->disable_reason);
        $this->assertSame((int) $admin->getKey(), (int) $cascaded->disabled_by);
    }

    #[Test]
    public function a_cascade_only_takes_down_dependents_that_are_still_on(): void
    {
        $this->switchModule('invoices', false);

        $this->assertNotContains('invoices', $this->modules->cascadeSet('clients'));
        $this->assertContains('projects', $this->modules->cascadeSet('clients'));
    }

    #[Test]
    public function once_the_dependents_are_off_the_module_can_be_disabled_without_a_cascade(): void
    {
        foreach (['time_tracking', 'tasks', 'payment_reversals', 'payments', 'project_payments', 'project_milestones', 'projects', 'invoices'] as $slug) {
            $this->modules->toggle($this->module($slug), false, 'wind down');
        }

        $this->modules->toggle($this->module('clients'), false, 'nothing depends on it now');

        $this->assertFalse((bool) $this->module('clients')->is_enabled);
    }

    #[Test]
    public function enabling_a_module_whose_dependency_is_off_is_allowed_and_warns(): void
    {
        $admin = $this->createSuperAdmin();

        $this->modules->toggle($this->module('projects'), false, 'Winding projects down', cascade: true);
        $this->modules->toggle($this->module('clients'), false, 'Winding clients down', cascade: true);

        $this->actingAs($admin)
            ->post('/admin/modules/'.$this->module('projects')->getKey().'/toggle', ['enabled' => true])
            ->assertSessionHas('toast', fn (array $toast): bool => str_contains($toast['message'], 'still switched off'));

        $this->assertTrue((bool) $this->module('projects')->is_enabled);
        $this->assertSame(['clients'], $this->modules->missingDependencies('projects'));
    }

    #[Test]
    public function a_core_module_cannot_be_disabled_by_the_service_the_endpoint_or_a_cascade(): void
    {
        $admin = $this->createSuperAdmin();

        foreach (['dashboard', 'users', 'roles', 'permissions', 'modules', 'settings', 'activity_log', 'login_history'] as $slug) {
            $module = $this->module($slug);

            try {
                $this->modules->toggle($module, false, 'Trying to switch a core module off', cascade: true);
                $this->fail($slug.' is core and must refuse to go off.');
            } catch (ActionNotAllowedException) {
                // expected
            }

            // A Super Admin passes the policy (Gate::before), so it is the service that refuses:
            // the answer is an error toast, never a success.
            $this->actingAs($admin)
                ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'cascade' => true, 'reason' => 'Trying to switch a core module off'])
                ->assertRedirect()
                ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error' && str_contains($toast['message'], 'core module'));

            $this->actingAs($this->createUserWithPermissions(['modules.view_any', 'modules.change_status']))
                ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'cascade' => true, 'reason' => 'Trying to switch a core module off'])
                ->assertForbidden();

            $this->assertTrue((bool) $module->fresh()->is_enabled, $slug.' must stay on.');
        }
    }

    #[Test]
    public function a_cascade_that_would_reach_a_core_module_is_refused_whole(): void
    {
        // A declaration a later phase could plausibly write: a core module leaning on a business one.
        DB::table('modules')->where('slug', 'global_search')->update(['depends_on' => json_encode(['clients'])]);

        try {
            $this->modules->toggle($this->module('clients'), false, 'Retiring the CRM entirely', cascade: true);
            $this->fail('A cascade must never smuggle a core module off.');
        } catch (ActionNotAllowedException $exception) {
            $this->assertStringContainsString('core module', $exception->getMessage());
        }

        $this->assertTrue((bool) $this->module('clients')->is_enabled);
        $this->assertTrue((bool) $this->module('projects')->is_enabled, 'Nothing in the chain moved.');
    }

    #[Test]
    public function the_impact_preview_names_the_dependents_routes_sidebar_and_promises_no_data_loss(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->getJson('/admin/modules/'.$this->module('clients')->getKey().'/impact')
            ->assertOk()
            ->assertJsonPath('action', 'disable')
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('requires_cascade', true)
            ->assertJsonPath('module.slug', 'clients')
            ->assertJsonPath('data_safety', fn (string $text): bool => str_contains($text, 'No data is deleted'))
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('blocking_dependents', fn ($rows): bool => collect($rows)->pluck('slug')->sort()->values()->all() === ['invoices', 'projects'])
                ->where('cascade', fn ($rows): bool => collect($rows)->pluck('slug')->contains('time_tracking'))
                ->has('routes.items')
                ->has('sidebar_items')
                ->etc());

        // Read-only: previewing changed nothing.
        $this->assertTrue((bool) $this->module('clients')->is_enabled);
    }

    #[Test]
    public function the_impact_preview_for_a_module_nothing_depends_on_is_not_blocked(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->getJson('/admin/modules/'.$this->module('time_tracking')->getKey().'/impact')
            ->assertOk()
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('blocking_dependents', []);
    }

    #[Test]
    public function a_bulk_disable_of_a_whole_group_orders_itself_and_needs_no_cascade(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->from('/admin/modules')
            ->post('/admin/modules/bulk-toggle', ['group' => 'hr', 'enabled' => false, 'reason' => 'HR outsourced'])
            ->assertRedirect('/admin/modules')
            ->assertSessionHas('toast');

        foreach (['employees', 'departments', 'attendance', 'leaves', 'payroll'] as $slug) {
            $this->assertFalse((bool) $this->module($slug)->is_enabled, $slug.' is in the HR group and must be off.');
        }
    }

    #[Test]
    public function a_bulk_disable_skips_a_module_still_needed_outside_the_batch_and_says_so(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post('/admin/modules/bulk-toggle', [
                'modules' => [$this->module('clients')->getKey(), $this->module('employees')->getKey()],
                'enabled' => false,
                'reason' => 'Trimming unused modules',
            ])
            ->assertSessionHas('toast', fn (array $toast): bool => str_contains($toast['message'], 'skipped'));

        $this->assertTrue((bool) $this->module('clients')->is_enabled, 'clients is still needed by projects and invoices.');
        $this->assertTrue((bool) $this->module('employees')->is_enabled, 'employees is still needed by attendance, leaves and payroll.');
    }

    /**
     * The cascade holds exactly the expected modules, and every dependent precedes the module it
     * depends on — so applying it in order never leaves an enabled module pointing at a dark one.
     *
     * @param  list<string>  $expected
     */
    private function assertCascadeGoesDeepestFirst(string $slug, array $expected): void
    {
        $set = $this->modules->cascadeSet($slug);

        $this->assertEqualsCanonicalizing($expected, $set);

        foreach ($set as $position => $dependent) {
            foreach ($this->modules->dependencies($dependent) as $dependency) {
                $at = array_search($dependency, $set, true);

                if ($at !== false) {
                    $this->assertGreaterThan($position, $at, sprintf('%s must go down before %s, which it depends on.', $dependent, $dependency));
                }
            }
        }
    }

    private function module(string $slug): Module
    {
        return Module::query()->where('slug', $slug)->firstOrFail();
    }
}
