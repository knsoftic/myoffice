<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Activity;
use App\Models\Branch;
use App\Models\LoginHistory;
use App\Models\Module;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\ModuleService;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use Database\Seeders\ModuleSeeder;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The four panels cannot be switched off by accident, and `Gate::before` gates every shape of
 * ability (phase-01 §4, §6.1, §10).
 *
 * `collaborator_portal`, `student_portal`, `teacher_portal` and `client_portal` are permission
 * namespaces for the non-admin panels, not feature areas. While they were ordinary non-core
 * modules, one click on "Student Portal" in /admin/modules denied `student_portal.*` to everyone —
 * Super Admin included — and every student got a bare 403 on their own dashboard, with no
 * `module:` middleware to explain it and no way back except finding the right switch again. They
 * are core now, which is what makes them render locked and refuse every path to "off".
 *
 * The second half of this file covers the other half of the same rule: the module denial has to be
 * decided (a) before spatie resolves the permission and before the Super Admin bypass, and
 * (b) for policy-style abilities (`update`, `viewAny`, …) as well as for dotted permission names,
 * because those carry no module in their name.
 */
final class PortalModuleProtectionTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The permission namespaces that are panels, not features. */
    private const PORTALS = ['collaborator_portal', 'student_portal', 'teacher_portal', 'client_portal'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function portalProvider(): array
    {
        return [
            'collaborator_portal' => ['collaborator_portal'],
            'student_portal' => ['student_portal'],
            'teacher_portal' => ['teacher_portal'],
            'client_portal' => ['client_portal'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | A panel is core
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('portalProvider')]
    public function a_portal_module_is_core_everywhere_it_is_asked(string $slug): void
    {
        $definition = PermissionRegistry::module($slug);

        $this->assertNotNull($definition, $slug.' must stay a registered module.');
        $this->assertTrue($definition['is_core'], $slug.' must be declared core in the registry.');
        $this->assertContains($slug, PermissionRegistry::coreSlugs());
        $this->assertTrue(Modules::isCore($slug), 'The module gate must treat '.$slug.' as core.');

        $module = Module::query()->where('slug', $slug)->firstOrFail();

        $this->assertTrue((bool) $module->is_core, 'The seeded row must carry is_core.');
        $this->assertTrue($module->isCore());
        $this->assertFalse($module->canBeDisabled(), 'A panel must not offer a working switch.');
    }

    #[Test]
    #[DataProvider('portalProvider')]
    public function a_portal_module_cannot_be_disabled_through_the_admin_screen(string $slug): void
    {
        $superAdmin = $this->createSuperAdmin();
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        // D63: a disable must carry a reason, so one is given — the refusal under test is the
        // core-module rule, not the missing-reason rule.
        $this->actingAs($superAdmin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Closing the whole panel'])
            ->assertRedirect('/admin/modules')
            ->assertSessionHas('toast.type', 'error');

        $this->assertTrue((bool) $module->fresh()->is_enabled);
        $this->assertTrue(Modules::enabled($slug));
    }

    #[Test]
    #[DataProvider('portalProvider')]
    public function the_module_service_refuses_to_disable_a_portal_module(string $slug): void
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        $this->expectException(ActionNotAllowedException::class);

        app(ModuleService::class)->setEnabled($module, false, 'Trying anyway');
    }

    /**
     * The stored flag is not the authority — the registry is. Even a row flipped behind the
     * application's back (a stale seed, a hand-edited database) leaves the panel open.
     */
    #[Test]
    public function a_portal_panel_survives_its_row_being_flipped_behind_the_applications_back(): void
    {
        $student = $this->seededDemoUser('Student');

        $this->actingAs($student)->get('/student')->assertOk();

        DB::table('modules')->whereIn('slug', self::PORTALS)->update(['is_enabled' => false]);
        Modules::flushCache();

        foreach (self::PORTALS as $slug) {
            $this->assertTrue(Modules::enabled($slug), $slug.' is core: it can never report as off.');
        }

        $this->assertTrue(Gate::forUser($student)->allows('student_portal.dashboard'));
        $this->actingAs($student)->get('/student')->assertOk();

        // And the same for the three other panels' own users.
        foreach ([['Teacher', '/teacher'], ['Client', '/client'], ['Collaborator', '/collaborator']] as [$role, $home]) {
            $this->actingAs($this->seededDemoUser($role))->get($home)->assertOk();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The seeder converges an already-seeded row
    |--------------------------------------------------------------------------
    */

    /**
     * The portal rows were seeded before they were core, so the fix is only real if re-running the
     * seeder repairs an existing installation — without touching the one thing an administrator
     * owns, the `is_enabled` flag of every other module.
     */
    #[Test]
    public function re_seeding_converges_a_stale_portal_row_without_touching_other_modules(): void
    {
        // The state a pre-fix installation is in: portals non-core, one of them switched off.
        DB::table('modules')->whereIn('slug', self::PORTALS)->update(['is_core' => false]);
        DB::table('modules')->where('slug', 'student_portal')->update(['is_enabled' => false]);

        // An administrator's own choice, which the seeder must leave alone.
        DB::table('modules')->where('slug', 'leads')->update(['is_enabled' => false]);
        $enabledBefore = $this->enabledFlags();

        Modules::flushCache();

        $this->seed(ModuleSeeder::class);

        foreach (self::PORTALS as $slug) {
            $row = Module::query()->where('slug', $slug)->firstOrFail();

            $this->assertTrue((bool) $row->is_core, $slug.' must converge to is_core.');
            $this->assertTrue((bool) $row->is_enabled, 'A core module can never be stored as disabled.');
        }

        $this->assertFalse(
            (bool) Module::query()->where('slug', 'leads')->firstOrFail()->is_enabled,
            'Re-seeding must never switch a module an administrator disabled back on.'
        );

        // Nothing else moved: only student_portal went from off to on.
        $expected = $enabledBefore;
        $expected['student_portal'] = true;

        $this->assertSame($expected, $this->enabledFlags());
    }

    /*
    |--------------------------------------------------------------------------
    | Gate::before — registered in boot(), still decided first
    |--------------------------------------------------------------------------
    */

    /**
     * The contract puts these rules in `AppServiceProvider::boot()` (§6). Boot order alone would
     * land them *behind* spatie's own before-callback, which returns true as soon as the user holds
     * the permission — so the module denial would silently never run for a permission holder.
     * This asserts the registration actually ends up in front.
     */
    #[Test]
    public function the_module_rules_are_the_first_before_callback_the_gate_consults(): void
    {
        $gate = $this->app->make(GateContract::class);

        $this->assertInstanceOf(AccessGate::class, $gate);

        $property = new ReflectionProperty(AccessGate::class, 'beforeCallbacks');
        /** @var array<int, callable> $callbacks */
        $callbacks = $property->getValue($gate);

        $this->assertNotEmpty($callbacks);

        $scopes = array_map(
            static fn (callable $callback): ?string => (new ReflectionFunction($callback))
                ->getClosureScopeClass()?->getName(),
            $callbacks,
        );

        $this->assertSame(
            AppServiceProvider::class,
            $scopes[0] ?? null,
            'The module denial must be evaluated before anything else, spatie included.'
        );

        $this->assertContains(
            PermissionRegistrar::class,
            $scopes,
            'spatie must still resolve permissions — our callback returns null to let it.'
        );
    }

    /**
     * The behavioural half of the same guarantee: a user who genuinely holds the permission is
     * still denied while the module is off. If the callbacks were the other way round, spatie would
     * answer true first and this would pass for the wrong reason.
     */
    #[Test]
    public function a_disabled_module_beats_a_permission_the_user_really_holds(): void
    {
        $user = $this->createUserWithPermissions(['projects.view_any', 'users.view_any']);

        $this->assertTrue($user->hasPermissionTo('projects.view_any'));
        $this->assertTrue(Gate::forUser($user)->allows('projects.view_any'));

        $this->switchModule('projects', false);

        $this->assertTrue(Gate::forUser($user)->denies('projects.view_any'));
        $this->assertTrue(
            $user->fresh()->hasPermissionTo('projects.view_any'),
            'The grant is untouched — it is the module gate that closed.'
        );
        $this->assertTrue(Gate::forUser($user)->allows('users.view_any'));
    }

    /*
    |--------------------------------------------------------------------------
    | Gate::before — policy-style abilities
    |--------------------------------------------------------------------------
    */

    /**
     * A policy check arrives as a bare method name (`update`), which names no module, so the module
     * has to be resolved from the subject or rule §6.1 has a hole: the Super Admin bypass would
     * answer true for a model whose module is switched off.
     *
     * Phase 1 owns no model outside the core modules, so the subject here is the shape a later
     * phase will have: a model that names its own module. The class => slug map and the naming
     * convention are covered by the cases below it.
     */
    #[Test]
    public function a_disabled_module_denies_a_policy_ability_for_a_super_admin_too(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $subject = $this->modelOfModule('projects');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $subject));

        $this->switchModule('projects', false);

        $this->assertTrue(
            Gate::forUser($superAdmin)->denies('update', $subject),
            'A Super Admin must not write to a module that is switched off.'
        );
        $this->assertTrue(Gate::forUser($superAdmin)->denies('viewAny', $subject));

        $this->switchModule('projects', true);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $subject));
    }

    #[Test]
    public function a_policy_ability_on_an_enabled_or_core_module_is_unaffected(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = User::factory()->create();

        // `users` is core, so no amount of flipping can close it.
        DB::table('modules')->where('slug', 'users')->update(['is_enabled' => false]);
        Modules::flushCache();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $target));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', User::class));
    }

    #[Test]
    public function the_subject_resolves_to_a_module_three_ways(): void
    {
        // 1. the model names its own module,
        $this->assertSame('projects', Modules::moduleForSubject($this->modelOfModule('projects')));

        // 2. the explicit class => slug map (including the classes whose slug is not their plural),
        $this->assertSame('users', Modules::moduleForSubject(User::class));
        $this->assertSame('users', Modules::moduleForSubject(User::factory()->make()));
        $this->assertSame('activity_log', Modules::moduleForSubject(Activity::class));
        $this->assertSame('login_history', Modules::moduleForSubject(LoginHistory::class));
        $this->assertSame('modules', Modules::moduleForSubject(Module::class));

        // 3. the project convention — snake_case plural of the model name — but only when it names
        //    a module the registry actually declares.
        $this->assertSame('projects', Modules::moduleForSubject(Project::class));
        $this->assertNull(Modules::moduleForSubject(Branch::class));
        $this->assertNull(Modules::moduleForSubject(NotAModule::class));

        // Anything that is not a subject at all stays unresolved: spatie passes a guard name as the
        // first argument, and that must never be read as a model.
        $this->assertNull(Modules::moduleForSubject('web'));
        $this->assertNull(Modules::moduleForSubject(null));
        $this->assertNull(Modules::moduleForSubject(42));

        // The dotted permission name always wins over the subject.
        $this->assertSame('projects', Modules::moduleForAbility('projects.view_any', User::class));
        $this->assertSame('users', Modules::moduleForAbility('update', User::class));
        $this->assertNull(Modules::moduleForAbility('update'));
    }

    /**
     * The guard-name convention spatie supports — `$user->can('users.view', 'web')` — must keep
     * working now that the first argument is inspected.
     */
    #[Test]
    public function a_permission_check_with_an_explicit_guard_still_resolves(): void
    {
        $user = $this->createUserWithPermissions(['users.view_any']);

        $this->assertTrue(Gate::forUser($user)->allows('users.view_any', 'web'));
        $this->assertTrue(Gate::forUser($user)->denies('roles.view_any', 'web'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A stand-in for a later phase's module-owned model: it names its module the way
     * Modules::SUBJECT_MODULE_METHOD expects.
     */
    private function modelOfModule(string $slug): object
    {
        return new class($slug)
        {
            public function __construct(private readonly string $slug) {}

            public function moduleSlug(): string
            {
                return $this->slug;
            }
        };
    }

    /**
     * slug => is_enabled for every module row.
     *
     * @return array<string, bool>
     */
    private function enabledFlags(): array
    {
        return DB::table('modules')
            ->orderBy('slug')
            ->pluck('is_enabled', 'slug')
            ->map(static fn (mixed $enabled): bool => (bool) $enabled)
            ->all();
    }
}

/**
 * Fixtures for the naming convention, deliberately empty: only their class names matter.
 * `Project` pluralises into the registered `projects` module; `NotAModule` pluralises into nothing
 * the registry declares, and must therefore resolve to null rather than gate anything.
 */
final class Project {}

final class NotAModule {}
