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
 * Phase 16's rows in the four D60 manifests, checked against the thing they describe.
 *
 * Phase 24's `audit:manifest --check` will police every phase at once; until it ships, each phase's own
 * drift check is part of its definition of done — a manifest nobody compares is a list, not a control.
 *
 * **This is the first phase whose rows cover three panels**, so the screen rows carry an IDOR owner
 * column for the two that scope every row to the signed-in person. An admin screen has no owner: it
 * shows whatever the permission allows, and the branch rule narrows it in the query rather than by
 * ownership.
 */
final class SchedulingManifestTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The name prefixes this phase owns. */
    private const PREFIXES = [
        'admin.teachers.', 'admin.classrooms.', 'admin.batches.', 'admin.enrollments.',
        'admin.timetable.', 'admin.class-sessions.',
        'teacher.batches.', 'teacher.students.', 'teacher.timetable.', 'teacher.sessions.',
        'teacher.demo-classes.',
        'student.batches.', 'student.timetable.', 'student.teachers.',
    ];

    /** The seven tables of phase-14-17 §2.17–§2.23. */
    private const TABLES = [
        'teachers', 'course_teacher', 'classrooms', 'batches',
        'student_batch_enrollments', 'timetable_entries', 'class_sessions',
    ];

    private const KINDS = ['index', 'show', 'form', 'board', 'calendar', 'wizard', 'print', 'public', 'export', 'dashboard', 'statement'];

    #[Test]
    public function every_phase_16_route_has_one_true_route_guard_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $permissions = PermissionRegistry::permissionNames();
        $live = $this->phase16Routes();

        $this->assertNotEmpty($live, 'Phase 16 registers routes.');

        foreach ($live as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('Route %s has no route-guard-manifest row.', $name));

            $row = $rows[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $can = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')));

            $this->assertSame($route->methods(), $row['methods'], sprintf('%s: methods drifted.', $name));
            $this->assertSame($middleware, $row['middleware'], sprintf('%s: middleware drifted.', $name));
            $this->assertSame($this->panelOf($name), $row['panel'], sprintf('%s: panel.', $name));
            $this->assertSame(
                array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [],
                $row['state_changing'],
                sprintf('%s: state_changing.', $name),
            );
            $this->assertSame(16, $row['owner_phase']);

            // Every Phase 16 route is guarded; none of them is public, so none carries a rationale.
            $this->assertNotEmpty($can, sprintf('%s has no can: guard at all.', $name));
            $this->assertNull($row['rationale'], sprintf('%s is guarded; a rationale would blur the two kinds of row.', $name));

            $literal = substr($can[0], 4);

            if (str_contains($literal, ',')) {
                // D87: `can:<ability>,<model>` is an ability on a model, so the row names both the
                // permission the policy requires and the method that also weighs the row's own state.
                $this->assertArrayHasKey('policy', $row, sprintf('%s is policy-guarded (%s).', $name, $literal));
                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z]+Policy::[a-zA-Z]+$/',
                    (string) $row['policy'],
                    sprintf('%s: policy reads as Class::method.', $name),
                );

                [$ability] = explode(',', $literal, 2);
                $this->assertStringEndsWith('::'.$ability, (string) $row['policy'], sprintf('%s: the method named must be the ability asked for.', $name));
            } else {
                $this->assertArrayNotHasKey('policy', $row, sprintf('%s is guarded by a bare permission.', $name));
                $this->assertSame($literal, $row['permission'], sprintf('%s: the permission must be the route\'s can:.', $name));
            }

            $this->assertContains(
                $row['permission'],
                $permissions,
                sprintf('%s names a permission PermissionRegistry does not declare.', $name),
            );
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 16) {
                $this->assertArrayHasKey($name, $live, sprintf('route-guard-manifest lists %s, which Phase 16 no longer registers.', $name));
            }
        }
    }

    #[Test]
    public function the_enrolment_routes_carry_the_two_abilities_the_split_exists_for(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));

        // §4.2: seating and transferring are `batches.assign`; changing an enrolment's status is a
        // decision about the student, so it is `students.change_status`.
        $this->assertSame('batches.assign', $rows['admin.batches.enrollments.store']['permission']);
        $this->assertSame('batches.assign', $rows['admin.enrollments.transfer']['permission']);
        $this->assertSame('students.change_status', $rows['admin.enrollments.status']['permission']);

        // And all four class moves sit behind the one status ability.
        foreach (['cancel', 'reschedule', 'substitute', 'held'] as $move) {
            $this->assertSame(
                'timetable.change_status',
                $rows['admin.class-sessions.'.$move]['permission'],
                sprintf('%s tells the roster something, so it is a status change.', $move),
            );
        }
    }

    #[Test]
    public function every_phase_16_get_route_has_a_screen_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));

        $gets = array_filter(
            $this->phase16Routes(),
            static fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true),
        );

        foreach ($gets as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('GET route %s has no screen-manifest row.', $name));

            $row = $rows[$name];

            $this->assertContains($row['kind'], self::KINDS, sprintf('%s: unknown kind.', $name));
            $this->assertSame($guards[$name]['panel'], $row['panel'], sprintf('%s: the two manifests must agree on the panel.', $name));
            $this->assertSame(16, $row['owner_phase']);
            $this->assertIsCallable($row['params'], sprintf('%s: params is a closure.', $name));

            $expected = $guards[$name]['permission'] === null ? [] : [$guards[$name]['permission']];

            $this->assertSame($expected, $row['permissions'], sprintf('%s: the screen row must ask for what the route asks for.', $name));
            $this->assertSame($this->moduleOf($guards[$name]['middleware']), $row['module'], sprintf('%s: module gate.', $name));

            // The two panels scope every row to the signed-in person; an admin screen has no owner.
            $row['panel'] === 'admin'
                ? $this->assertNull($row['idor']['owner'], sprintf('%s: an admin screen has no ownership column.', $name))
                : $this->assertNotNull($row['idor']['owner'], sprintf('%s: a panel screen is owned by whoever is signed in.', $name));
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 16) {
                $this->assertArrayHasKey($name, $gets, sprintf('screen-manifest lists %s, which is not a Phase 16 GET route.', $name));
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
            $columns = array_map(
                static fn (array $list): string => $list[0],
                $manifest[(string) $key->t] ?? [],
            );

            $this->assertContains(
                (string) $key->c,
                $columns,
                sprintf('F-9.2: the foreign key %s.%s leads no index-manifest row.', $key->t, $key->c),
            );
        }
    }

    #[Test]
    public function the_three_generated_guards_are_listed_where_they_actually_are(): void
    {
        $manifest = $this->manifest('index-manifest');

        $expectations = [
            ['student_batch_enrollments', 'current_guard', 'one live seat per student per batch'],
            ['timetable_entries', 'active_guard', 'a live weekly slot'],
            ['timetable_entries', 'room_guard', 'a slot that actually occupies a room'],
            ['class_sessions', 'active_guard', 'a live class'],
            ['class_sessions', 'room_guard', 'a class that actually occupies a room'],
        ];

        foreach ($expectations as [$table, $guard, $what]) {
            $found = false;

            foreach ($manifest[$table] as $columns) {
                if (in_array($guard, $columns, true)) {
                    $found = true;

                    break;
                }
            }

            $this->assertTrue($found, sprintf('%s.%s (%s) appears in no listed index.', $table, $guard, $what));
        }
    }

    #[Test]
    public function this_phase_accepts_no_upload_at_all(): void
    {
        $rows = collect($this->manifest('upload-manifest'))->where('owner_phase', 16);

        $this->assertCount(0, $rows);

        // A teacher's photo is the one file §72 mentions, and it arrives as a `photo_path` chosen from
        // the media library rather than as an upload of this phase's own — so there is no route here
        // that takes a file, and the manifest says so by having nothing to say.
        foreach ($this->phase16Routes() as $name => $route) {
            foreach ($this->manifest('upload-manifest') as $row) {
                $this->assertNotSame($name, $row['route'], sprintf('%s accepts a file and has no row.', $name));
            }
        }
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
    private function phase16Routes(): array
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

    private function panelOf(string $name): string
    {
        if (str_starts_with($name, 'teacher.')) {
            return 'teacher';
        }

        if (str_starts_with($name, 'student.')) {
            return 'student';
        }

        return 'admin';
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
