<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\Sidebar;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * One permission string per thing, stated identically everywhere it is stated (CLAUDE.md §4,
 * phase-01 §10 "granting the exact permission makes it 200").
 *
 * The bug this file exists to prevent: the activity log's route required
 * `can:activity_log.view_logs`, the sidebar item gated on `activity_log.view_any` and the
 * controller accepted either. All three abilities exist, so nothing failed loudly — a role granted
 * `view_any` saw the menu item and then got a hard 403 from the route, and a role granted
 * `view_logs` could open the screen but never saw the link. Which permission "makes it 200"
 * depended on which layer you asked.
 *
 * These tests walk the real route table and the real sidebar declaration rather than a list typed
 * out here, so the same class of drift cannot come back quietly in a later phase: a new route whose
 * `can:` names a permission nobody seeds, or a sidebar item that advertises a different string from
 * the route it points at, fails here the moment it is written.
 */
final class PermissionStringConsistencyTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Phase 1 registers 25 admin routes plus four panel dashboards, every one with a `can:`. */
    private const MINIMUM_GUARDED_ROUTES = 29;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    /**
     * Every `can:` in the route table has to name a permission that exists — in the database and
     * in the registry that seeds it. A typo, a renamed ability or a permission a later phase
     * forgets to register would otherwise produce a route nobody can ever reach: `can:` denies an
     * unknown ability for everyone except Super Admin, silently.
     */
    #[Test]
    public function every_route_permission_exists_in_the_permissions_table(): void
    {
        $seeded = Permission::query()->pluck('name')->all();
        $registered = PermissionRegistry::permissionNames();

        $checked = 0;

        foreach ($this->guardedRoutes() as $name => $abilities) {
            foreach ($abilities as $ability) {
                $checked++;

                $this->assertContains(
                    $ability,
                    $seeded,
                    sprintf('Route %s requires "%s", which is not in the permissions table.', $name, $ability)
                );

                $this->assertContains(
                    $ability,
                    $registered,
                    sprintf('Route %s requires "%s", which PermissionRegistry does not declare.', $name, $ability)
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_GUARDED_ROUTES,
            $checked,
            'The walker found almost no guarded routes — it is passing vacuously, not proving anything.'
        );
    }

    /**
     * Every permission the sidebar advertises must exist too, including the items whose routes
     * arrive in a later phase: the sidebar is built from the same registry, so an item naming a
     * permission nobody seeds is a typo, not a forward declaration.
     */
    #[Test]
    public function every_sidebar_permission_exists_in_the_permissions_table(): void
    {
        $seeded = Permission::query()->pluck('name')->all();
        $checked = 0;

        foreach ($this->sidebarItems() as $panel => $items) {
            foreach ($items as $item) {
                // An item may and two permissions, exactly as a route may carry two `can:` entries
                // (phase-13 §7.5). Every name in the rule is checked, not just the first.
                $permission = Sidebar::permissionOf($item);

                if ($permission === null) {
                    continue;
                }

                foreach ((array) $permission as $name) {
                    $checked++;

                    $this->assertContains(
                        $name,
                        $seeded,
                        sprintf(
                            'The %s sidebar item "%s" gates on "%s", which is not a seeded permission.',
                            $panel,
                            (string) ($item['label'] ?? '?'),
                            $name,
                        )
                    );
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'The sidebar walker found almost no items.');
    }

    /**
     * The heart of the finding: where a sidebar item points at a route that exists, the item and
     * the route must state the same rule. Anything else means a visible link that 403s, or a
     * reachable screen with no link.
     */
    #[Test]
    public function the_sidebar_and_the_route_it_points_at_require_the_same_permission(): void
    {
        $guarded = $this->guardedRoutes();
        $compared = 0;

        foreach ($this->sidebarItems() as $panel => $items) {
            foreach ($items as $item) {
                $name = $item['route'] ?? null;
                $permission = Sidebar::permissionOf($item);

                if (! is_string($name) || $permission === null || ! Route::has($name)) {
                    continue;
                }

                if (! array_key_exists($name, $guarded)) {
                    continue;
                }

                $compared++;

                // The whole rule, not its first name: a route carrying two `can:` entries and an item
                // advertising one of them is a visible link that 403s, which is what this asserts
                // against.
                $this->assertSame(
                    $guarded[$name],
                    array_values((array) $permission),
                    sprintf(
                        'The %s sidebar item "%s" advertises a different permission from the route %s it links to.',
                        $panel,
                        (string) ($item['label'] ?? '?'),
                        $name,
                    )
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            11,
            $compared,
            'Every Phase-1 sidebar item with a live route has to be compared (7 admin + 4 panels).'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The two log modules, end to end
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function logModuleProvider(): array
    {
        return [
            'activity log' => ['/admin/activity-log', 'activity_log.view_logs', 'Activity Log'],
            'login history' => ['/admin/login-history', 'login_history.view_logs', 'Login History'],
        ];
    }

    #[Test]
    #[DataProvider('logModuleProvider')]
    public function the_exact_log_permission_opens_the_screen_and_shows_the_link(string $url, string $permission, string $label): void
    {
        $user = $this->createUserWithPermissions([$permission]);

        $this->actingAs($user)->get($url)->assertOk();

        $this->assertContains(
            $label,
            $this->labelsFor($user),
            sprintf('%s opens %s, so the sidebar must offer it.', $permission, $url)
        );
    }

    /**
     * The other direction of the same rule: the abilities that are *not* the read rule grant
     * neither the screen nor the link. Before the fix, `view_any` showed the link and then 403'd.
     */
    #[Test]
    #[DataProvider('logModuleProvider')]
    public function a_neighbouring_log_ability_grants_neither_the_screen_nor_the_link(string $url, string $permission, string $label): void
    {
        $module = explode('.', $permission, 2)[0];

        $user = $this->createUserWithPermissions([$module.'.view_any', $module.'.view']);

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNotContains(
            $label,
            $this->labelsFor($user),
            sprintf('%s.view_any does not open %s, so it must not advertise it either.', $module, $url)
        );
    }

    /**
     * The controller's own check and the route middleware have to agree as well, or the layered
     * defence becomes a second, different rule. Proven by calling the screen with the route
     * middleware stripped: the controller must still refuse the neighbouring ability.
     */
    #[Test]
    #[DataProvider('logModuleProvider')]
    public function the_controller_enforces_the_same_permission_as_the_route(string $url, string $permission): void
    {
        // Strip the route's `can:` so the controller's own check is the only thing deciding.
        $this->withoutMiddleware(Authorize::class);

        $module = explode('.', $permission, 2)[0];

        $this->actingAs($this->createUserWithPermissions([$module.'.view_any']))
            ->get($url)
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions([$permission]))
            ->get($url)
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * route name => the dotted permissions its `can:` middleware requires.
     *
     * Policy-style abilities (`can:update,user`) are skipped: they name a policy method, not a
     * permission row.
     *
     * @return array<string, array<int, string>>
     */
    private function guardedRoutes(): array
    {
        $guarded = [];

        /** @var RouteInstance $route */
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $ability = explode(',', substr($middleware, strlen('can:')))[0];

                if (! str_contains($ability, '.')) {
                    continue;
                }

                $key = $name !== '' ? $name : $route->uri();

                $guarded[$key] = array_values(array_unique([...$guarded[$key] ?? [], $ability]));
            }
        }

        return $guarded;
    }

    /**
     * Every declared sidebar item of every panel, children included, keyed by panel.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function sidebarItems(): array
    {
        $items = [];

        foreach (['admin', 'collaborator', 'student', 'teacher', 'client'] as $panel) {
            $items[$panel] = [];

            foreach (Sidebar::tree($panel) as $group) {
                $items[$panel] = array_merge($items[$panel], $this->flatten($group['items'] ?? []));
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;

            if (is_array($item['children'] ?? null)) {
                $flat = array_merge($flat, $this->flatten($item['children']));
            }
        }

        return $flat;
    }

    /**
     * The labels a user actually sees in their sidebar.
     *
     * @return array<int, string>
     */
    private function labelsFor(User $user): array
    {
        $labels = [];

        foreach (Sidebar::forUser($user) as $group) {
            foreach ($group['items'] as $item) {
                $labels[] = (string) $item['label'];
            }
        }

        return $labels;
    }
}
