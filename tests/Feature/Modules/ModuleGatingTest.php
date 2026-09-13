<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Models\Permission;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\ModuleService;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Module gating (phase-01 §1.3, §6, §10, decision D5).
 *
 * Switching a module off must:
 *   · deny every one of its abilities to everyone — Super Admin included, because `Gate::before`
 *     checks the module *before* the Super Admin short-circuit;
 *   · 403 the routes that belong to it;
 *   · leave every row it owns exactly where it was, so switching it back on restores the feature
 *     rather than rebuilding it.
 *
 * Core modules are structural and can never be switched off — and the registry, not the stored
 * row, is what decides which modules those are.
 */
final class ModuleGatingTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The one Phase-1 route group guarded by `module:` (routes/collaborator.php). */
    private const COLLABORATOR_HOME = '/collaborator';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | The Gate
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function disabling_a_module_denies_all_of_its_abilities_to_a_super_admin(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $abilities = PermissionRegistry::permissionNamesFor('projects');

        $this->assertNotEmpty($abilities);

        foreach ($abilities as $ability) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows($ability));
        }

        $this->switchModule('projects', false);

        foreach ($abilities as $ability) {
            $this->assertTrue(
                Gate::forUser($superAdmin)->denies($ability),
                sprintf('%s must be denied while the projects module is disabled.', $ability)
            );
        }
    }

    #[Test]
    public function disabling_a_module_denies_its_abilities_to_a_user_who_holds_them(): void
    {
        $user = $this->createUserWithPermissions(['projects.view_any', 'projects.edit', 'users.view_any']);

        $this->assertTrue(Gate::forUser($user)->allows('projects.view_any'));

        $this->switchModule('projects', false);

        $this->assertTrue(Gate::forUser($user)->denies('projects.view_any'));
        $this->assertTrue(Gate::forUser($user)->denies('projects.edit'));

        // The grant itself is untouched — it is the gate that closed, not the role.
        $this->assertTrue($user->fresh()->hasPermissionTo('projects.view_any'));

        // And no other module is affected.
        $this->assertTrue(Gate::forUser($user)->allows('users.view_any'));
    }

    #[Test]
    public function re_enabling_a_module_restores_its_abilities(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->switchModule('projects', false);
        $this->assertTrue(Gate::forUser($superAdmin)->denies('projects.view_any'));

        $this->switchModule('projects', true);
        $this->assertTrue(Gate::forUser($superAdmin)->allows('projects.view_any'));
    }

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function disabling_the_owning_module_closes_the_panel_for_a_super_admin(): void
    {
        // Give the Super Admin a collaborator-panel role so `panel:collaborator` passes and the
        // module gate is the only thing left deciding.
        $superAdmin = $this->createSuperAdmin();
        $superAdmin->assignRole('Collaborator');
        $this->forgetPermissionCache();
        $superAdmin = $superAdmin->fresh();

        $this->actingAs($superAdmin)->get(self::COLLABORATOR_HOME)->assertOk();

        $this->switchModule('collaborators', false);

        $this->actingAs($superAdmin)->get(self::COLLABORATOR_HOME)->assertForbidden();

        $this->switchModule('collaborators', true);

        $this->actingAs($superAdmin)->get(self::COLLABORATOR_HOME)->assertOk();
    }

    #[Test]
    public function disabling_the_owning_module_closes_the_panel_for_its_own_users(): void
    {
        $collaborator = $this->seededDemoUser('Collaborator');

        $this->actingAs($collaborator)->get(self::COLLABORATOR_HOME)->assertOk();

        $this->switchModule('collaborators', false);

        $this->actingAs($collaborator)->get(self::COLLABORATOR_HOME)->assertForbidden();

        $this->switchModule('collaborators', true);

        $this->actingAs($collaborator)->get(self::COLLABORATOR_HOME)->assertOk();
    }

    /**
     * The other half of the same route: `collaborator_portal` is the permission namespace the
     * `can:` middleware names, and it is a **core** module, so it can never take the panel away.
     *
     * It used to be an ordinary non-core module, which made "Collaborator Portal" a working switch
     * on /admin/modules that denied `collaborator_portal.*` to everyone — Super Admin included —
     * and gave every collaborator a bare 403 on their own dashboard, with no `module:` middleware to
     * explain it. One module closes this route (`collaborators`, asserted above); the namespace
     * behind the `can:` does not. See Modules\PortalModuleProtectionTest for the full rule.
     */
    #[Test]
    public function the_portal_namespace_behind_the_can_middleware_can_never_close_the_panel(): void
    {
        $collaborator = $this->seededDemoUser('Collaborator');

        $this->actingAs($collaborator)->get(self::COLLABORATOR_HOME)->assertOk();

        // Not through switchModule(): the switchboard refuses a core module outright, so this is the
        // worst case — the stored flag forced off behind the application's back.
        DB::table('modules')->where('slug', 'collaborator_portal')->update(['is_enabled' => false]);
        Modules::flushCache();

        $this->assertTrue(Modules::enabled('collaborator_portal'));
        $this->assertTrue(Gate::forUser($collaborator)->allows('collaborator_portal.dashboard'));

        $this->actingAs($collaborator)->get(self::COLLABORATOR_HOME)->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Data is untouched
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function disabling_a_module_leaves_its_rows_untouched(): void
    {
        $module = Module::query()->where('slug', 'projects')->firstOrFail();

        $before = [
            'permissions' => Permission::query()->where('module', 'projects')->orderBy('name')->pluck('name')->all(),
            'grants' => $this->grantCountFor('projects'),
            'name' => (string) $module->name,
            'group' => $module->group->value,
            'icon' => (string) $module->icon,
            'sort_order' => (int) $module->sort_order,
            'is_core' => (bool) $module->is_core,
            'settings' => $module->settings,
        ];

        $this->switchModule('projects', false);

        $after = Module::query()->where('slug', 'projects')->firstOrFail();

        $this->assertFalse((bool) $after->is_enabled, 'The only column that may move is is_enabled.');

        $this->assertSame(
            $before['permissions'],
            Permission::query()->where('module', 'projects')->orderBy('name')->pluck('name')->all(),
            'Disabling a module must not delete its permissions.'
        );

        $this->assertSame(
            $before['grants'],
            $this->grantCountFor('projects'),
            'Disabling a module must not revoke the grants roles already hold on it.'
        );

        $this->assertSame($before['name'], (string) $after->name);
        $this->assertSame($before['group'], $after->group->value);
        $this->assertSame($before['icon'], (string) $after->icon);
        $this->assertSame($before['sort_order'], (int) $after->sort_order);
        $this->assertSame($before['is_core'], (bool) $after->is_core);
        $this->assertSame($before['settings'], $after->settings);
    }

    /*
    |--------------------------------------------------------------------------
    | Core modules
    |--------------------------------------------------------------------------
    */

    /**
     * The System group. The four `*_portal` namespaces are core too, and are covered — switch,
     * service, row and panel — by Modules\PortalModuleProtectionTest rather than repeated here.
     *
     * @return array<string, array{0: string}>
     */
    public static function coreModuleProvider(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'users' => ['users'],
            'roles' => ['roles'],
            'permissions' => ['permissions'],
            'modules' => ['modules'],
            'settings' => ['settings'],
            'activity_log' => ['activity_log'],
            'login_history' => ['login_history'],
            'backups' => ['backups'],
            'global_search' => ['global_search'],
        ];
    }

    /**
     * A Super Admin reaches the toggle action (`Gate::before` answers the `toggle` ability before
     * ModulePolicy can refuse it), so the refusal is the service's: the request comes back with an
     * error toast and the module is still on. For anyone else the policy refuses it with a 403 —
     * see Rbac\PermissionEnforcementTest.
     */
    #[Test]
    #[DataProvider('coreModuleProvider')]
    public function a_core_module_cannot_be_disabled_through_the_admin_screen(string $slug): void
    {
        $superAdmin = $this->createSuperAdmin();
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        // D63: a disable must carry a reason, so one is given — the refusal under test is the
        // core-module rule, not the missing-reason rule.
        $this->actingAs($superAdmin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Trying to switch a core module off'])
            ->assertRedirect('/admin/modules')
            ->assertSessionHas('toast.type', 'error')
            ->assertSessionHas('toast.message', sprintf('"%s" is a core module and can never be disabled.', $module->name));

        $this->assertTrue((bool) $module->fresh()->is_enabled);
        $this->assertTrue(Module::enabled($slug));
    }

    /**
     * An administrator who is not a Super Admin is refused by ModulePolicy::toggle() outright.
     */
    #[Test]
    public function a_core_module_toggle_is_a_403_for_an_ordinary_administrator(): void
    {
        $actor = $this->createUserWithPermissions(['modules.view_any', 'modules.change_status']);
        $module = Module::query()->where('slug', 'users')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $module->fresh()->is_enabled);
    }

    #[Test]
    public function the_module_service_refuses_to_disable_a_core_module(): void
    {
        $module = Module::query()->where('slug', 'users')->firstOrFail();

        $this->expectException(ActionNotAllowedException::class);

        app(ModuleService::class)->setEnabled($module, false, 'Trying anyway');
    }

    /**
     * Even with the stored flag flipped behind the application's back, a core module stays on: the
     * registry decides what is core, not the row (D5).
     */
    #[Test]
    public function a_core_module_stays_enabled_even_when_its_row_says_otherwise(): void
    {
        DB::table('modules')->where('slug', 'users')->update(['is_enabled' => false]);
        Modules::flushCache();

        $this->assertTrue(Module::enabled('users'));
        $this->assertTrue(Modules::enabled('users'));

        $superAdmin = $this->createSuperAdmin();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('users.view_any'));
        $this->actingAs($superAdmin)->get('/admin/users')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The switchboard itself
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_toggle_endpoint_flips_a_non_core_module_both_ways(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $module = Module::query()->where('slug', 'leads')->firstOrFail();

        $this->actingAs($superAdmin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Not sold yet'])
            ->assertRedirect('/admin/modules');

        $this->assertFalse((bool) $module->fresh()->is_enabled);

        $this->actingAs($superAdmin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true])
            ->assertRedirect('/admin/modules');

        $this->assertTrue((bool) $module->fresh()->is_enabled);
    }

    #[Test]
    public function an_unknown_module_slug_is_treated_as_enabled_so_a_fresh_install_never_locks_itself_out(): void
    {
        $this->assertTrue(
            Modules::enabled('a_module_that_was_never_seeded'),
            'An unseeded slug must not silently close the application down.'
        );

        $this->assertFalse(Modules::exists('a_module_that_was_never_seeded'));
    }

    /**
     * How many role grants point at a module's permissions.
     */
    private function grantCountFor(string $module): int
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.module', $module)
            ->count();
    }
}
