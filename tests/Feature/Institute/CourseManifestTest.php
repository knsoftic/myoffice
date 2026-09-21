<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 14's rows in the four D60 manifests, checked against the thing they describe.
 *
 * Phase 24's `audit:manifest --check` will police every phase at once; until it ships, each phase's own
 * drift check is part of its definition of done — a manifest nobody compares is a list, not a control.
 *
 * **This phase is the first to guard a route with a policy, so it is the first whose rows need a shape
 * the earlier ones did not (D87).** `can:update,course` is an ability on a model: the text after `can:`
 * is not a permission name, `PermissionRegistry` does not declare it, and recording `null` would file a
 * policy-guarded route beside a deliberately public one — which is the confusion the `rationale` column
 * exists to prevent. So such a row carries both: `permission`, the ability the policy requires, and
 * `policy`, the method that also weighs the course's own state.
 */
final class CourseManifestTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The four public routes of phase-14-17 §7.10; every other Phase 14 route is under `admin.`. */
    private const PUBLIC_ROUTES = [
        'site.courses.index', 'site.courses.category', 'site.courses.show', 'site.courses.resource',
    ];

    /** The name prefixes this phase owns. */
    private const PREFIXES = [
        'admin.course-categories.', 'admin.courses.', 'admin.course-outline.', 'admin.course-modules.',
        'admin.course-topics.', 'admin.course-lectures.', 'admin.course-resources.',
        'admin.course-topic-assignments.', 'site.courses.',
    ];

    /** The seven tables of phase-14-17 §2.4–§2.9. */
    private const TABLES = [
        'course_categories', 'courses', 'course_modules', 'course_topics', 'course_lectures',
        'course_topic_resources', 'course_topic_assignments',
    ];

    private const KINDS = ['index', 'show', 'form', 'board', 'calendar', 'wizard', 'print', 'public', 'export', 'dashboard', 'statement'];

    #[Test]
    public function every_phase_14_route_has_one_true_route_guard_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $permissions = PermissionRegistry::permissionNames();
        $live = $this->phase14Routes();

        $this->assertNotEmpty($live, 'Phase 14 registers routes.');

        foreach ($live as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('Route %s has no route-guard-manifest row.', $name));

            $row = $rows[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $can = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')));

            $this->assertSame($route->methods(), $row['methods'], sprintf('%s: methods drifted.', $name));
            $this->assertSame($middleware, $row['middleware'], sprintf('%s: middleware drifted.', $name));
            $this->assertSame(
                in_array($name, self::PUBLIC_ROUTES, true) ? 'public' : 'admin',
                $row['panel'],
                sprintf('%s: panel.', $name),
            );
            $this->assertSame(
                array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [],
                $row['state_changing'],
                sprintf('%s: state_changing.', $name),
            );
            $this->assertSame(14, $row['owner_phase']);

            if ($can === []) {
                $this->assertNull($row['permission'], sprintf('%s carries no can: and so holds no permission.', $name));
                $this->assertIsString($row['rationale'], sprintf('%s has no can: and so needs a written rationale.', $name));
                $this->assertGreaterThan(
                    40,
                    mb_strlen(trim((string) $row['rationale'])),
                    sprintf('%s: the rationale must say why, not merely exist.', $name),
                );

                continue;
            }

            $this->assertNull(
                $row['rationale'],
                sprintf('%s is guarded; a rationale would blur the two kinds of row.', $name),
            );

            $literal = substr($can[0], 4);
            $isPolicy = str_contains($literal, ',');

            if ($isPolicy) {
                // D87. The route says `can:<ability>,<model>`; the row says which permission that
                // policy method requires and which method it is, because the literal is neither.
                $this->assertArrayHasKey(
                    'policy',
                    $row,
                    sprintf('%s is guarded by a policy (%s) and its row must name the method.', $name, $literal),
                );
                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z]+Policy::[a-zA-Z]+$/',
                    (string) $row['policy'],
                    sprintf('%s: policy reads as Class::method.', $name),
                );

                [$ability] = explode(',', $literal, 2);
                $this->assertStringEndsWith(
                    '::'.$ability,
                    (string) $row['policy'],
                    sprintf('%s: the policy method named must be the ability the route asks for.', $name),
                );
            } else {
                $this->assertArrayNotHasKey(
                    'policy',
                    $row,
                    sprintf('%s is guarded by a bare permission; a policy key would claim a gate it does not have.', $name),
                );
                $this->assertSame($literal, $row['permission'], sprintf('%s: the permission must be the route\'s can:.', $name));
            }

            $this->assertContains(
                $row['permission'],
                $permissions,
                sprintf('%s names a permission PermissionRegistry does not declare.', $name),
            );
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 14) {
                $this->assertArrayHasKey($name, $live, sprintf('route-guard-manifest lists %s, which Phase 14 no longer registers.', $name));
            }
        }
    }

    #[Test]
    public function every_phase_14_get_route_has_a_screen_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));

        $gets = array_filter(
            $this->phase14Routes(),
            static fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true),
        );

        foreach ($gets as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('GET route %s has no screen-manifest row.', $name));

            $row = $rows[$name];

            $this->assertContains($row['kind'], self::KINDS, sprintf('%s: unknown kind.', $name));
            $this->assertSame($guards[$name]['panel'], $row['panel'], sprintf('%s: the two manifests must agree on the panel.', $name));
            $this->assertSame(14, $row['owner_phase']);
            $this->assertIsCallable($row['params'], sprintf('%s: params is a closure.', $name));

            $expected = $guards[$name]['permission'] === null ? [] : [$guards[$name]['permission']];

            $this->assertSame(
                $expected,
                $row['permissions'],
                sprintf('%s: the screen row must ask for what the route asks for.', $name),
            );

            $module = $guards[$name]['panel'] === 'public'
                ? null
                : $this->moduleOf($guards[$name]['middleware']);

            $this->assertSame($module, $row['module'], sprintf('%s: module gate.', $name));
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 14) {
                $this->assertArrayHasKey($name, $gets, sprintf('screen-manifest lists %s, which is not a Phase 14 GET route.', $name));
            }
        }
    }

    #[Test]
    public function every_listed_index_exists_and_every_foreign_key_has_a_row(): void
    {
        $manifest = $this->manifest('index-manifest');

        foreach (self::TABLES as $table) {
            $this->assertArrayHasKey($table, $manifest, sprintf('index-manifest has no entry for %s.', $table));

            $leading = DB::select(sprintf('SHOW INDEX FROM `%s` WHERE Seq_in_index = 1', $table));
            $names = array_map(static fn (object $row): string => (string) $row->Column_name, $leading);

            foreach ($manifest[$table] as $columns) {
                $this->assertContains(
                    $columns[0],
                    $names,
                    sprintf('index-manifest lists %s(%s), which no index of the schema leads with.', $table, implode(', ', $columns)),
                );
            }
        }

        // F-9.2: every foreign key column carries an index of its own, and says so here.
        $keys = DB::select(
            'SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ('
            .implode(',', array_fill(0, count(self::TABLES), '?')).')',
            self::TABLES,
        );

        foreach ($keys as $key) {
            $this->assertContains(
                [(string) $key->c],
                $manifest[(string) $key->t] ?? [],
                sprintf('F-9.2: the foreign key %s.%s has no index-manifest row of its own.', $key->t, $key->c),
            );
        }
    }

    #[Test]
    public function the_one_upload_route_has_a_row_and_it_is_not_on_the_public_disk(): void
    {
        $rows = collect($this->manifest('upload-manifest'))->where('owner_phase', 14)->values();

        $this->assertCount(1, $rows, 'Phase 14 accepts exactly one upload: a syllabus resource.');

        $row = $rows->first();

        $this->assertSame('admin.course-resources.store', $row['route']);
        $this->assertSame('file', $row['field']);
        $this->assertNotSame(
            'public',
            $row['disk'],
            'D21/D85: a resource that is not is_public needs a permission, and a file the web server serves has none in front of it.',
        );
        $this->assertFalse($row['public_reachable']);
        $this->assertSame('course_outline.upload', $row['permission']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manifest(string $file): array
    {
        return require base_path('tests/Support/'.$file.'.php');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByRoute(array $rows): array
    {
        $byRoute = [];

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey($row['route'], $byRoute, sprintf('%s is listed twice.', $row['route']));
            $byRoute[$row['route']] = $row;
        }

        return $byRoute;
    }

    /**
     * @return array<string, RoutingRoute>
     */
    private function phase14Routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $routes[$name] = $route;

                    break;
                }
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function moduleOf(array $middleware): ?string
    {
        foreach ($middleware as $one) {
            if (str_starts_with($one, 'module:')) {
                return substr($one, 7);
            }
        }

        return null;
    }
}
