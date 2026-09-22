<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Institute\StudentAttendance;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 17's rows in the four D60 manifests, checked against the thing they describe.
 *
 * Two of these tests exist because of what went wrong while the phase was being built.
 *
 * **The route table lies quietly when two phases claim one name.** Phase 7 already owned
 * `admin.attendance.*` for employee attendance; seven of this phase's names matched it exactly. Laravel
 * keeps the *last* registration in the name lookup and the *first* match in the URI dispatcher, so the
 * two halves disagreed with each other: `route('admin.attendance.index')` built the institute's URL
 * while a request to `/admin/attendance` reached Phase 7's controller. Every screen probe passed,
 * because Phase 7's screen is also a 200. `no_route_of_this_phase_collides_with_phase_7` is the test
 * that would have caught it in a second (D98).
 *
 * **A register is corrected, never deleted** (INV-I10), which is a claim about the whole surface and not
 * about one policy method: `Gate::before` hands a Super Admin every ability before a policy is consulted,
 * so the only place the invariant can be made true for everybody is the model — and the only way to be
 * sure no route offers the operation is to count the routes.
 */
final class RegisterManifestTest extends TestCase
{
    use RefreshDatabase;

    /** The name prefixes this phase owns. */
    private const PREFIXES = [
        'admin.student-attendance.', 'admin.student-progress.',
        'teacher.attendance.', 'teacher.progress.', 'teacher.reports.attendance',
        'student.attendance.', 'student.progress.',
    ];

    /** The five tables of phase-14-17 §2.24–§2.28. */
    private const TABLES = [
        'student_attendances', 'batch_topic_coverage', 'student_course_progress',
        'student_module_progress', 'student_topic_progress',
    ];

    private const KINDS = ['index', 'show', 'form', 'board', 'calendar', 'wizard', 'print', 'public', 'export', 'dashboard', 'statement'];

    #[Test]
    public function every_phase_17_route_has_one_true_route_guard_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $permissions = PermissionRegistry::permissionNames();
        $live = $this->phase17Routes();

        $this->assertNotEmpty($live, 'Phase 17 registers routes.');

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
            $this->assertSame(17, $row['owner_phase']);

            $this->assertNotEmpty($can, sprintf('%s has no can: guard at all.', $name));
            $this->assertNull($row['rationale'], sprintf('%s is guarded; a rationale would blur the two kinds of row.', $name));

            $literal = substr($can[0], 4);

            if (str_contains($literal, ',')) {
                $this->assertArrayHasKey('policy', $row, sprintf('%s is policy-guarded (%s).', $name, $literal));

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
            if (($row['owner_phase'] ?? null) === 17) {
                $this->assertArrayHasKey($name, $live, sprintf('route-guard-manifest lists %s, which Phase 17 no longer registers.', $name));
            }
        }
    }

    #[Test]
    public function no_route_of_this_phase_collides_with_phase_7(): void
    {
        // D98. The contract's §7.7 asked for `admin/attendance`, which Phase 7 has owned since long
        // before the institute existed. Both halves of the collision are asserted, because either one
        // alone is survivable and the pair is what made the bug invisible: the name lookup kept the
        // last registration, the dispatcher kept the first URI.
        $employee = Route::getRoutes()->getByName('admin.attendance.index');

        $this->assertNotNull($employee, 'Phase 7 still owns admin.attendance.index.');
        $this->assertSame('admin/attendance', $employee->uri());
        $this->assertStringContainsString(
            'Hr',
            (string) $employee->getActionName(),
            'admin.attendance.index must still reach the HR controller, not an institute one.',
        );

        foreach ($this->phase17Routes() as $name => $route) {
            $this->assertStringStartsNotWith(
                'admin.attendance.',
                $name,
                sprintf('%s claims a name Phase 7 owns.', $name),
            );
            $this->assertStringStartsNotWith(
                'admin/attendance/',
                $route->uri(),
                sprintf('%s sits under Phase 7\'s URI prefix, so the dispatcher may reach it first.', $name),
            );
            $this->assertNotSame('admin/attendance', $route->uri(), sprintf('%s is Phase 7\'s URI exactly.', $name));
        }

        // And the sidebar entry Phase 1 reserved is the name the phase actually registers.
        $this->assertNotNull(Route::getRoutes()->getByName('admin.student-attendance.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.student-progress.index'));
    }

    #[Test]
    public function the_phase_offers_no_way_to_delete_a_register_or_a_progress_row(): void
    {
        // INV-I10 and §4.2. Not "the policy says no" — there is no endpoint to say no to.
        foreach ($this->phase17Routes() as $name => $route) {
            $this->assertNotContains('DELETE', $route->methods(), sprintf('%s is a DELETE route.', $name));
            $this->assertStringEndsNotWith('.destroy', $name, sprintf('%s is named for a destroy action.', $name));
        }

        // A revision is `edit`, and it is the phase's one policy-guarded route.
        $rows = collect($this->manifest('route-guard-manifest'))->where('owner_phase', 17);

        $this->assertSame(
            ['admin.student-attendance.update'],
            $rows->filter(static fn (array $row): bool => array_key_exists('policy', $row))
                ->pluck('route')->values()->all(),
        );

        // §4.2: `student_progress` declares no delete ability, so no route here could ask for one.
        $declared = PermissionRegistry::permissionNames();

        $this->assertNotContains('student_progress.delete', $declared);
        $this->assertNotContains('student_progress.restore', $declared);
        $this->assertContains('student_progress.change_status', $declared, 'Dropping a topic raises a published percentage, so it is a status change.');

        // Attendance keeps its `delete`/`restore` names only because the module matrix is uniform; the
        // policy refuses both outright, which `RegisterAuthorizationTest` asserts.
        $this->assertContains('student_attendance.edit', $declared);
    }

    #[Test]
    public function every_phase_17_get_route_has_a_screen_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));

        $gets = array_filter(
            $this->phase17Routes(),
            static fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true),
        );

        foreach ($gets as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('GET route %s has no screen-manifest row.', $name));

            $row = $rows[$name];

            $this->assertContains($row['kind'], self::KINDS, sprintf('%s: unknown kind.', $name));
            $this->assertSame($guards[$name]['panel'], $row['panel'], sprintf('%s: the two manifests must agree on the panel.', $name));
            $this->assertSame(17, $row['owner_phase']);
            $this->assertIsCallable($row['params'], sprintf('%s: params is a closure.', $name));

            $expected = $guards[$name]['permission'] === null ? [] : [$guards[$name]['permission']];

            $this->assertSame($expected, $row['permissions'], sprintf('%s: the screen row must ask for what the route asks for.', $name));
            $this->assertSame($this->moduleOf($guards[$name]['middleware']), $row['module'], sprintf('%s: module gate.', $name));

            $row['panel'] === 'admin'
                ? $this->assertNull($row['idor']['owner'], sprintf('%s: an admin screen has no ownership column.', $name))
                : $this->assertNotNull($row['idor']['owner'], sprintf('%s: a panel screen is owned by whoever is signed in.', $name));

            // A CSV is not a page, so it is in neither HTML sweep; a print view has one fixed width.
            if ($row['response'] === 'csv') {
                $this->assertFalse($row['responsive'], sprintf('%s answers a file.', $name));
                $this->assertFalse($row['a11y'], sprintf('%s answers a file.', $name));
            } elseif ($row['kind'] === 'print') {
                $this->assertFalse($row['responsive'], sprintf('%s is laid out for paper.', $name));
                $this->assertTrue($row['a11y'], sprintf('%s is still HTML somebody reads on screen first.', $name));
            } else {
                $this->assertTrue($row['responsive'], sprintf('%s is a page.', $name));
                $this->assertTrue($row['a11y'], sprintf('%s is a page.', $name));
            }
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 17) {
                $this->assertArrayHasKey($name, $gets, sprintf('screen-manifest lists %s, which is not a Phase 17 GET route.', $name));
            }
        }
    }

    #[Test]
    public function the_two_grids_carry_the_budget_of_a_grid(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));

        // The monthly matrix and the batch progress board are the two screens that render a
        // student × something grid, and they are the two that need more than a list's budget.
        foreach (['admin.student-attendance.reports.monthly', 'admin.student-progress.batch', 'teacher.progress.show'] as $name) {
            $this->assertSame('board', $rows[$name]['kind'], sprintf('%s is a grid.', $name));
            $this->assertGreaterThan(30, $rows[$name]['query_budget'], sprintf('%s: a grid costs more than a list.', $name));
        }

        // And the marking screen is a form, because that is the one screen used on a phone at a door.
        $this->assertSame('form', $rows['admin.student-attendance.mark']['kind']);
        $this->assertSame('form', $rows['teacher.attendance.mark']['kind']);
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

        // F-9.2: every foreign key column leads an index, and says so here.
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
    public function the_unique_guard_every_upsert_relies_on_is_listed_and_unique(): void
    {
        $manifest = $this->manifest('index-manifest');

        // Each of these is written by an upsert keyed on this index, so it has to be listed and it has
        // to really be UNIQUE — an upsert against a plain index writes a second row instead of the
        // first one, and nothing complains.
        $guards = [
            ['student_attendances', ['class_session_id', 'student_id'], 'one row per student per class'],
            ['batch_topic_coverage', ['batch_id', 'course_topic_id'], 'one coverage row per topic per batch'],
            ['student_topic_progress', ['student_course_progress_id', 'course_topic_id'], 'one row per topic per student'],
            ['student_module_progress', ['student_course_progress_id', 'course_module_id'], 'one row per module per student'],
            ['student_course_progress', ['student_batch_enrollment_id'], 'one progress row per seat'],
        ];

        foreach ($guards as [$table, $columns, $what]) {
            $this->assertContains($columns, $manifest[$table], sprintf('%s: %s is not listed.', $table, $what));

            $unique = false;

            foreach (DB::select(sprintf('SHOW INDEX FROM `%s`', $table)) as $row) {
                if ((int) $row->Non_unique !== 0) {
                    continue;
                }

                $all = array_map(
                    static fn (object $one): string => (string) $one->Column_name,
                    DB::select(sprintf('SHOW INDEX FROM `%s` WHERE Key_name = ?', $table), [$row->Key_name]),
                );

                if ($all === $columns) {
                    $unique = true;

                    break;
                }
            }

            $this->assertTrue($unique, sprintf('%s(%s) must be UNIQUE — %s.', $table, implode(', ', $columns), $what));
        }
    }

    #[Test]
    public function no_soft_delete_column_sits_under_a_unique_guard_unreachably(): void
    {
        // [D-IN-2] and D19. These three are append-only and carry no `deleted_at` at all: the row is
        // the evidence, and a nullable column outside the unique index would let one `->delete()` hide
        // a row from every aggregate while still occupying the guard the next upsert needs.
        foreach (['batch_topic_coverage', 'student_module_progress', 'student_topic_progress'] as $table) {
            $this->assertFalse(
                DB::getSchemaBuilder()->hasColumn($table, 'deleted_at'),
                sprintf('%s is append-only ([D-IN-2]); a deleted_at would sit under its unique guard.', $table),
            );
        }

        // The other two do carry one, because §2.24 and §2.26 ask for it to honour CLAUDE.md §3 — and
        // a `deleted_at` outside a unique index is a hazard whoever asked for it, so each of the two
        // closes it a different way, and this is the test that says so.

        // §2.24: the model refuses every delete, soft or hard, so the column can never be written.
        // The refusal lives in the model rather than the policy because `Gate::before` hands a Super
        // Admin every ability before a policy is consulted (INV-I10).
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('student_attendances', 'deleted_at'));
        $this->assertContains(
            SoftDeletes::class,
            class_uses_recursive(StudentAttendance::class),
            'student_attendances has the column, so the model must model it rather than ignore it.',
        );

        $attendance = new StudentAttendance;
        $attendance->exists = true;

        try {
            $attendance->delete();
            $this->fail('Deleting a register row must throw: a register is corrected, never removed.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('amend', mb_strtolower($e->getMessage()), 'The refusal must say what to do instead.');
        }

        // §2.26: a progress row is derived, so refusing the delete would be theatre — `openFor()`
        // restores a trashed row instead, which is what keeps the seat from being wedged for good.
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('student_course_progress', 'deleted_at'));
        $this->assertStringContainsString(
            'withTrashed()',
            (string) file_get_contents(base_path('app/Services/Institute/CourseProgressService.php')),
            'openFor() must look through the soft-delete scope, or a trashed row makes the next open a 1062.',
        );
    }

    #[Test]
    public function this_phase_accepts_no_upload_at_all(): void
    {
        $uploads = $this->manifest('upload-manifest');

        $this->assertCount(0, collect($uploads)->where('owner_phase', 17));

        // `admin.student-attendance.import` exists and reads nothing: a half-built importer that dropped
        // rows quietly would be worse than none, so the route answers with a message saying so. It has no
        // upload row because it takes no file — when the importer ships, it brings its row with it.
        foreach ($this->phase17Routes() as $name => $route) {
            foreach ($uploads as $row) {
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
     * @return array<int|string, mixed>
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
    private function phase17Routes(): array
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
