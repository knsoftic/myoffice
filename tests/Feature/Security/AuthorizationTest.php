<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\PanelType;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Who may reach what, decided on the server every single time (phase-24-25 sections 11.1 and 11.2,
 * SEC-13, SEC-19..SEC-23, SEC-28, SEC-32, SEC-33).
 *
 * **Hiding a button is not security** (CLAUDE.md golden rule 7), and this class is what makes that
 * sentence cost something. Every route is asked three questions: does it refuse a user who holds
 * nothing, does it *accept* a user who holds exactly the permission it declares, and does it refuse
 * a stranger. The middle one is the one people forget to write, and it is the one that catches a
 * route guarded by the **wrong** permission - a mistake no amount of "403 works" testing finds,
 * because the route does refuse, just not the people it was supposed to refuse.
 *
 * **The order of the guards is itself a control** (SEC-22). `panel:` runs before `can:`, so a
 * Student who somehow held `leads.view_any` is still refused `/admin/leads` - the panel decides
 * where a person may be before the permission decides what they may do there. A system that
 * checked only the permission would let one bad role grant cross a panel boundary.
 *
 * **Mass assignment is authorization too** (SEC-13). A request that cannot reach a route can still
 * reach a column, if the column is fillable and the form posts it: `status=active` on your own
 * suspended profile is a privilege escalation that never touches a policy. Its sibling SEC-12 -
 * the same attack read from the model side - lives in `CsrfAndInjectionTest` beside SEC-11, because
 * the two share one scan of `app/Models/**` and two copies of that scan could disagree.
 */
#[Group('security')]
final class AuthorizationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Owner-scoped foreign keys a create/update request must validate against the owner (SEC-33). */
    private const OWNER_SCOPED_KEYS = [
        'client_id', 'project_id', 'student_id', 'collaborator_id', 'batch_id', 'invoice_id',
        'student_fee_id', 'payout_account_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-13 - self-promotion, on the profile and in the role editor
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-13. A person cannot promote themselves, on their own profile or through the role editor.
     *
     * **The suspended user is the sharp case.** They are still authenticated for exactly one more
     * request - `EnsureUserIsActive` signs them out on the next one - and in that window their own
     * profile form is the shortest path from `suspended` to `active` that exists in the system.
     */
    #[Test]
    public function test_profile_update_cannot_escalate(): void
    {
        $user = $this->createUserWithPermissions(['roles.view_any', 'roles.edit'], PanelType::Admin);

        $user->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($user)->put(route('account.profile.update', [], false), [
            'name' => 'Still Suspended',
            'locale' => $user->locale ?? 'en',
            'status' => 'active',
            'role' => User::SUPER_ADMIN_ROLE,
            'branch_id' => 2,
            'email_verified_at' => now()->toDateTimeString(),
            'must_change_password' => false,
        ]);

        $fresh = $user->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('suspended', (string) ($fresh->status instanceof \BackedEnum ? $fresh->status->value : $fresh->status), 'SEC-13: a suspended user reactivated themselves.');
        $this->assertFalse($fresh->hasRole(User::SUPER_ADMIN_ROLE), 'SEC-13: a profile post granted a role.');

        // The role editor: holding roles.edit is not holding permissions.edit, and the permission
        // sync is the part that hands out power.
        $user->forceFill(['status' => 'active'])->save();
        $this->forgetPermissionCache();

        $role = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        if (Route::getRoutes()->getByName('admin.roles.update') === null) {
            $this->markTestSkipped('admin.roles.update is not registered; SEC-13 asserted the profile half only.');
        }

        $this->actingAs($user->fresh())->put(route('admin.roles.update', ['role' => $role->getKey()], false), [
            'name' => $role->name,
            'panel' => PanelType::Admin->value,
            'level' => 60,
            'permissions' => PermissionRegistry::permissionNames(),
        ]);

        $this->assertSame(
            [],
            $role->fresh()?->permissions->pluck('name')->all() ?? [],
            'SEC-13: roles.edit without permissions.edit synced permissions.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-19 - the role editor's own hazard
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-19. The full permission set survives the round trip.
     *
     * **This is not an authorization test, it is a truncation test, and it is here because the
     * symptom looks like authorization.** PHP's `max_input_vars` silently discards inputs past the
     * limit - no warning to the user, no error in the log - so a role editor posting several
     * hundred checkboxes saves the first thousand fields and drops the rest. The administrator sees
     * a success toast and a role that quietly lost permissions.
     */
    #[Test]
    public function test_role_permission_matrix_posts_completely(): void
    {
        $limit = (int) ini_get('max_input_vars');

        $this->assertGreaterThanOrEqual(
            5000,
            $limit === 0 ? 5000 : $limit,
            'SEC-19: max_input_vars is below 5000 (§6.9.7) - the role editor will truncate silently.',
        );

        if (Route::getRoutes()->getByName('admin.roles.update') === null) {
            $this->markTestSkipped('admin.roles.update is not registered.');
        }

        $names = PermissionRegistry::permissionNames();

        $this->assertGreaterThan(200, count($names), 'SEC-19: the registry declares too few permissions for this test to mean anything.');
        $this->assertLessThan(
            ($limit === 0 ? 5000 : $limit) * 0.8,
            count($names) + 10,
            'SEC-19: the submitted input count is within 80% of max_input_vars - raise the limit before the next module lands.',
        );

        $admin = $this->createSuperAdmin();
        $role = $this->createRoleWithPermissions([], PanelType::Admin, 60);

        $this->actingAs($admin)->put(route('admin.roles.update', ['role' => $role->getKey()], false), [
            'name' => $role->name,
            'panel' => PanelType::Admin->value,
            'level' => 60,
            'permissions' => $names,
        ])->assertRedirect();

        $stored = $role->fresh()?->permissions->pluck('name')->all() ?? [];

        sort($names);
        sort($stored);

        $this->assertSame($names, $stored, sprintf('SEC-19: %d permissions posted, %d persisted.', count($names), count($stored)));
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-20, SEC-21, SEC-22 - the route sweep
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-20. Every route is accounted for, in both directions.
     *
     * A route with no manifest row is a route nobody decided about; a manifest row with no route is
     * a promise about something that no longer exists. Both are the same defect - the document and
     * the system disagreeing - and `audit:manifest --check` is the gate that says so in CI.
     */
    #[Test]
    public function test_every_route_is_accounted_for(): void
    {
        $exit = Artisan::call('audit:manifest', ['--check' => true]);

        $this->assertSame(0, $exit, "SEC-20: audit:manifest --check failed.\n".Artisan::output());
    }

    /**
     * SEC-21. Three questions of every route in the manifest.
     *
     * (a) a user holding **no** permission is refused; (b) a user holding **exactly** the declared
     * permission is not refused - this is the one that catches the wrong permission on the right
     * route; (c) a stranger is sent to the sign-in screen. For a write route, (a) additionally
     * proves nothing was written.
     *
     * (b) is asserted for the staff panels only. A portal route also demands a *profile row* -
     * `EnsureClientContext` and its siblings - so a permission-holding user with no client record
     * is refused for a reason that has nothing to do with the permission. That half belongs to the
     * five-panel matrix of section 11.3, which seeds the profiles, and is cited rather than
     * rewritten here.
     */
    #[Test]
    #[DataProvider('guardedRoutes')]
    public function test_authorization_on_every_route(string $name, ?string $permission, string $panel, bool $stateChanging): void
    {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            $this->fail(sprintf('SEC-21: route-guard-manifest lists %s, which is no longer registered.', $name));
        }

        if ($panel === 'public') {
            $this->markTestSkipped($name.' is public: it has no permission to assert.');
        }

        $uri = $this->uriFor($route, $name);
        $verb = $stateChanging ? $this->writeVerb((array) $route->methods()) : 'GET';

        // (c) A stranger never sees a panel screen, whatever the permission says.
        $guest = $this->call($verb, $uri);
        $this->assertContains(
            $guest->getStatusCode(),
            [302, 401, 404, 419],
            sprintf('SEC-21(c): %s answered %d for a guest.', $name, $guest->getStatusCode()),
        );

        if ($permission === null) {
            $this->markTestSkipped($name.' is deliberately unguarded; its rationale is the manifest row (SEC-20 checks it).');
        }

        $panelType = $this->panelType($panel);

        // (a) Holding nothing. 404 is an acceptable refusal where the convention is 404 (an id the
        // caller may not even know exists), and a parameterised route substituted with a made-up id
        // answers 404 from SubstituteBindings, which runs in the `web` group ahead of `can:`.
        $nobody = $this->createUserWithPermissions([], $panelType);
        $before = $this->sentinelCounts();

        $denied = $this->actingAs($nobody)->call($verb, $uri);

        $this->assertContains(
            $denied->getStatusCode(),
            [403, 404],
            sprintf('SEC-21(a): %s answered %d for a user holding no permission.', $name, $denied->getStatusCode()),
        );

        if ($stateChanging) {
            $this->assertSame($before, $this->sentinelCounts(), sprintf('SEC-21(a): the refused %s still wrote a row.', $name));
        }

        if (! in_array($panel, ['admin', 'account', 'shared'], true)) {
            $this->markTestSkipped($name.' is a portal route: the positive path needs a seeded profile row (section 11.3 PanelMatrixTest owns it).');
        }

        // (b) Holding exactly this permission. Never 403 - a 403 here means the route is guarded by
        // a permission other than the one it declares.
        $holder = $this->createUserWithPermissions([$permission], $panelType);

        $allowed = $this->actingAs($holder)->call($verb, $uri);

        $this->assertNotSame(
            403,
            $allowed->getStatusCode(),
            sprintf('SEC-21(b): %s refused a user holding exactly %s.', $name, $permission),
        );
    }

    /**
     * SEC-22. The panel decides before the permission does.
     *
     * A role grant that crosses a panel boundary is a mistake somebody makes eventually. This is
     * the control that makes it harmless: the Student is refused `/admin/leads` by `panel:admin`
     * before `can:leads.view_any` is ever consulted, so the impossible grant buys nothing.
     */
    #[Test]
    public function test_panel_middleware_precedes_permission(): void
    {
        $student = $this->createUserWithPermissions(['leads.view_any'], PanelType::Student);

        $this->actingAs($student)
            ->get(route('admin.leads.index', [], false))
            ->assertForbidden();

        $admin = $this->createUserWithPermissions([], PanelType::Admin);

        $response = $this->actingAs($admin)->get(route('collaborator.dashboard', [], false));

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            'SEC-22: an Admin reached the collaborator panel.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-23, SEC-28 - the gates that move
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-23. A disabled module denies everyone - Super Admin included - and loses nothing.
     *
     * **"Disabled" must mean invisible, not deleted.** The whole value of a module switch is that
     * an administrator can turn a module off for a quarter and back on with every row still there;
     * a switch that quietly stopped a scheduled command from running, or let a row be pruned while
     * the module was off, would be a data-loss feature wearing a settings toggle.
     *
     * `Gate::before` denies a disabled module's ability first, ahead of the Super Admin bypass -
     * which is the only ordering that makes the switch mean anything at all.
     */
    #[Test]
    public function test_module_gating_denies_everyone_and_preserves_data(): void
    {
        $admin = $this->createSuperAdmin();
        $core = PermissionRegistry::coreSlugs();

        $byModule = [];

        foreach ($this->manifestRows() as $row) {
            $permission = $row['permission'] ?? null;

            if (! is_string($permission) || ($row['state_changing'] ?? false) === true || ($row['panel'] ?? '') !== 'admin') {
                continue;
            }

            $module = strtok($permission, '.');

            if ($module === false || in_array($module, $core, true) || isset($byModule[$module])) {
                continue;
            }

            $route = Route::getRoutes()->getByName((string) $row['route']);

            if ($route !== null && $route->parameterNames() === []) {
                $byModule[$module] = (string) $row['route'];
            }
        }

        $this->assertNotEmpty($byModule, 'SEC-23: no non-core admin module had a parameterless GET route to test.');

        $before = $this->tableCounts();

        foreach ($byModule as $module => $routeName) {
            $this->switchModule($module, false);
            $this->forgetPermissionCache();

            $response = $this->actingAs($admin)->get(route($routeName, [], false));

            $this->assertSame(
                403,
                $response->getStatusCode(),
                sprintf('SEC-23: %s answered %d for a Super Admin while module %s was disabled.', $routeName, $response->getStatusCode(), $module),
            );

            $this->switchModule($module, true);
            $this->forgetPermissionCache();
        }

        $this->assertSame($before, $this->tableCounts(), 'SEC-23: disabling and re-enabling modules changed row counts.');

        $this->markTestSkipped('SEC-23 asserted the deny and the row counts: the sidebar-absence, public-404 and "scheduled commands still ran" halves need the ops fixtures of section 10.4, which do not exist yet.');
    }

    /**
     * SEC-28. A revoked permission is gone on the next request, not on the next sign-in.
     *
     * **A permission cache that outlives a revocation is a revocation that did not happen.** The
     * person whose access was withdrawn keeps it until their session ends, which is precisely the
     * window in which somebody revokes access.
     */
    #[Test]
    public function test_permission_revocation_takes_effect_next_request(): void
    {
        $user = $this->createUserWithPermissions(['leads.view_any'], PanelType::Admin);

        $this->actingAs($user)->get(route('admin.leads.index', [], false))->assertOk();

        /** @var Role $role */
        $role = $user->roles->first();
        $role->syncPermissions([]);
        $this->forgetPermissionCache();

        $this->actingAs($user->fresh())
            ->get(route('admin.leads.index', [], false))
            ->assertForbidden();

        // And the other direction: a grant works without a re-login.
        $this->grantPermissions($user, 'leads.view_any');

        $this->actingAs($user->fresh())->get(route('admin.leads.index', [], false))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-32, SEC-33 - the same permission, somebody else's row
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-32. A tenant asking for a neighbour's id gets 404, never 403 and never 200.
     *
     * **404 rather than 403 is deliberate.** A 403 confirms the row exists, which is half of what
     * an enumeration run wants to know; a 404 makes "not yours" and "not there" indistinguishable
     * from the outside.
     */
    #[Test]
    #[Group('idor')]
    public function test_idor_sweep(): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require base_path('tests/Support/screen-manifest.php');

        $owned = array_values(array_filter(
            $rows,
            static fn (array $row): bool => is_array($row['idor'] ?? null) && ($row['idor']['owner'] ?? null) !== null,
        ));

        $this->assertNotEmpty($owned, 'SEC-32: no screen declares an idor owner - the sweep would have nothing to do.');

        foreach ($owned as $row) {
            $this->assertNotSame(
                'public',
                (string) $row['panel'],
                sprintf('SEC-32: %s is public and still declares an owner column.', $row['route']),
            );

            $this->assertTrue(
                Route::getRoutes()->getByName((string) $row['route']) !== null,
                sprintf('SEC-32: %s declares an owner but is not registered.', $row['route']),
            );
        }

        $this->markTestSkipped(sprintf(
            'SEC-32 asserted the manifest for all %d owner-scoped screens: the four-tenant-pair sweep needs the fixture builder of section 11.4 (two clients, two students, two collaborators, two teachers), which does not exist yet.',
            count($owned),
        ));
    }

    /**
     * SEC-33. A foreign id in the body is rejected by the rule, not by luck.
     *
     * `exists:projects,id` asks whether the row exists. `Rule::exists('projects', 'id')->where(...)`
     * asks whether it exists **and belongs to this caller**, and the difference between the two is
     * every cross-tenant write in the system. A bare `exists` also fails open in a quieter way: the
     * row is accepted, the service loads it, and the isolation error surfaces as a 500 somewhere
     * downstream where it reads like a bug rather than an attack.
     *
     * **Scoped to the panels that have a tenant, because that is what SEC-33 is about.** The scan
     * used to read every Form Request in the tree and reported six, and all six were admin:
     * `StoreCommissionAdjustmentRequest`, `StorePayoutRequest`, `StoreInvoiceRequest`,
     * `ProjectListRequest`, `StoreProjectRequest`, `UpdateProjectRequest`. An administrator raising
     * an invoice picks *any* client - there is no owner to scope the rule to, and
     * `Rule::exists()->where('client_id', ...)` on an admin form would either be a tautology or a
     * bug. The attack this row describes is **a tenant posting a neighbour's id**, so the scan asks
     * the route table which panel each request is actually reachable on and reads only the ones
     * that are not admin-only.
     *
     * The panel comes from the routes rather than from the directory name: `app/Http/Requests/
     * Project/StoreProjectRequest.php` sits outside the `Admin` folder and is nonetheless
     * admin-only, and a folder is a filing decision while a `panel:` middleware is the fact. A
     * request no route type-hints is treated as **in scope** - fail closed, because an unreachable
     * request is either dead code or reached some way this scan cannot see.
     */
    #[Test]
    public function test_relationship_ids_in_payloads_are_validated_against_the_owner(): void
    {
        $offenders = [];
        $panels = $this->formRequestPanels();
        $skipped = [];

        foreach (File::allFiles(app_path('Http/Requests')) as $file) {
            $source = (string) File::get($file->getPathname());
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $relative = 'app/Http/Requests/'.$relativePath;
            $class = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            // Admin-only: there is no tenant to scope an id to, so a bare `exists` is correct.
            if (($panels[$class] ?? []) === ['admin']) {
                $skipped[] = $relative;

                continue;
            }

            foreach (self::OWNER_SCOPED_KEYS as $key) {
                // A bare string rule with no Rule::exists()->where() anywhere near it.
                $pattern = '/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>\s*\[(?<rules>[^\]]*)\]/s';

                if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $match) {
                    $rules = $match['rules'];

                    if (! str_contains($rules, 'exists')) {
                        continue;
                    }

                    if (str_contains($rules, 'Rule::exists') || str_contains($rules, '->where(')) {
                        continue;
                    }

                    $offenders[] = $relative.'::'.$key;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            'SEC-33: a portal request validates an owner-scoped foreign key with a bare exists rule: '.implode(', ', array_unique($offenders)),
        );

        // The scan has to have had something to read: a mapping bug that silently classified every
        // request as admin-only would otherwise make this test pass by scanning nothing at all.
        $this->assertNotEmpty($panels, 'SEC-33: no Form Request could be mapped to a route, so nothing was scanned.');
        $this->assertGreaterThan(
            0,
            count($panels) - count($skipped),
            'SEC-33: every Form Request was treated as admin-only, so the scan read nothing.',
        );

        $this->markTestSkipped(sprintf(
            'SEC-33 asserted the rule shape of every request reachable outside the admin panel (%d admin-only requests hold no tenant to scope to): the "post a foreign id and expect 422" half needs the two-tenant fixture builder of section 11.4, which does not exist yet.',
            count($skipped),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Providers and fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Every Form Request, mapped to the panels of the routes that actually type-hint it (SEC-33).
     *
     * **The route table is the authority on which panel a request belongs to, not the folder.**
     * `Requests/Project/StoreProjectRequest` lives outside `Requests/Admin` and is reached only
     * through `admin.projects.store`; `Requests/Portal/RaiseTicketRequest` is reached from four
     * portals at once. Reading the `panel:` middleware off the routes is what makes the scan
     * follow the product when a request is later reused somewhere it was not written for - which
     * is exactly the change that would quietly turn an admin-only bare `exists` into a
     * cross-tenant hole.
     *
     * A route with no `panel:` middleware is public, and public is not admin, so its request is
     * scanned.
     *
     * @return array<class-string, list<string>>
     */
    private function formRequestPanels(): array
    {
        $panels = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            $panel = 'public';

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'panel:')) {
                    $panel = substr($middleware, strlen('panel:'));
                }
            }

            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $name = $type->getName();

                if (! is_subclass_of($name, FormRequest::class)) {
                    continue;
                }

                $panels[$name][$panel] = true;
            }
        }

        return array_map(
            static fn (array $found): array => array_keys($found),
            $panels,
        );
    }

    /**
     * Every manifest row, as the sweep sees it.
     *
     * Read from the file: a data provider runs before the application exists, so `Route::getRoutes()`
     * is not available here. SEC-20 is what keeps the file and the route table equal.
     *
     * @return iterable<string, array{0: string, 1: ?string, 2: string, 3: bool}>
     */
    public static function guardedRoutes(): iterable
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require dirname(__DIR__, 2).'/Support/route-guard-manifest.php';

        foreach ($rows as $row) {
            $name = (string) $row['route'];
            $permission = $row['permission'] ?? null;

            yield $name => [
                $name,
                is_string($permission) ? $permission : null,
                (string) ($row['panel'] ?? 'admin'),
                ($row['state_changing'] ?? false) === true,
            ];
        }
    }

    /** @return list<array<string, mixed>> */
    private function manifestRows(): array
    {
        return require base_path('tests/Support/route-guard-manifest.php');
    }

    /**
     * A URI for the route, with real parameters where the screen manifest can supply them.
     *
     * A made-up id answers 404 from `SubstituteBindings`, which runs inside the `web` group ahead of
     * `can:` - so the sweep would be asserting that binding works rather than that authorization
     * does. The screen manifest's `params` closures read seeded rows, which is exactly what is
     * wanted; one that cannot resolve falls back to the placeholder and the 404 is accepted.
     */
    private function uriFor(\Illuminate\Routing\Route $route, string $name): string
    {
        static $params = null;

        if ($params === null) {
            $params = [];

            foreach (require base_path('tests/Support/screen-manifest.php') as $row) {
                $params[(string) $row['route']] = $row['params'];
            }
        }

        if (isset($params[$name])) {
            try {
                return route($name, ($params[$name])(), false);
            } catch (Throwable) {
                // No fixture for this screen in the production seed; fall through.
            }
        }

        return '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
    }

    private function panelType(string $panel): PanelType
    {
        return match ($panel) {
            'collaborator' => PanelType::Collaborator,
            'student' => PanelType::Student,
            'teacher' => PanelType::Teacher,
            'client' => PanelType::Client,
            default => PanelType::Admin,
        };
    }

    /** @param  list<string>  $methods */
    private function writeVerb(array $methods): string
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            if (in_array($verb, $methods, true)) {
                return $verb;
            }
        }

        return 'POST';
    }

    /** @return array<string, int> */
    private function sentinelCounts(): array
    {
        $counts = [];

        foreach (['users', 'model_has_roles', 'collaborator_commission_ledger_entries'] as $table) {
            $counts[$table] = DB::getSchemaBuilder()->hasTable($table) ? DB::table($table)->count() : -1;
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];

        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $name = is_string($table) ? $table : (string) ($table['name'] ?? '');

            if ($name === '' || str_contains($name, '.')) {
                $name = (string) preg_replace('/^.*\./', '', (string) $name);
            }

            if ($name === '' || in_array($name, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'activity_log'], true)) {
                continue;
            }

            $counts[$name] = DB::table($name)->count();
        }

        return $counts;
    }

}
