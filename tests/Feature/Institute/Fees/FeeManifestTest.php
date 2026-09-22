<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 18's rows in the four D60 manifests, checked against the thing they describe.
 *
 * Phase 17's equivalent found two real defects on the day it was written — a seven-way route-name
 * collision with Phase 7 and a soft-delete column that could wedge a seat for ever — so this one goes
 * further than "every route has a row". It asserts the claims the phase actually makes: that a waiver
 * needs two abilities, that no route can edit or delete a discount, that the student panel's rows all
 * carry an owner, and that `uq_sfr_dedupe` is on the generated column rather than the nullable key.
 */
final class FeeManifestTest extends TestCase
{
    use RefreshDatabase;

    /** The twenty-five routes this phase registers (§7). Phase 10's eight payment routes are not ours. */
    private const ROUTES = [
        'admin.student-fees.index', 'admin.student-fees.create', 'admin.student-fees.store',
        'admin.student-fees.show', 'admin.student-fees.slip', 'admin.student-fees.export',
        'admin.student-fees.cancel', 'admin.student-fees.reopen', 'admin.student-fees.generate-monthly',
        'admin.student-fees.installments.store', 'admin.student-fees.installments.rebuild',
        'admin.student-fees.discounts.store',
        'admin.fee-structures.preview', 'admin.fee-structures.store', 'admin.fee-structures.slip',
        'admin.fee-collection.index',
        'admin.installments.preview', 'admin.installments.waive',
        'admin.fee-discounts.reverse',
        'admin.fee-reminders.index', 'admin.fee-reminders.store',
        'student.fees.index', 'student.fees.show', 'student.fees.slip', 'student.payments.receipt',
    ];

    private const KINDS = ['index', 'show', 'form', 'board', 'calendar', 'wizard', 'print', 'public', 'export', 'dashboard', 'statement'];

    #[Test]
    public function every_phase_18_route_has_one_true_route_guard_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $permissions = PermissionRegistry::permissionNames();
        $live = $this->phase18Routes();

        $this->assertCount(count(self::ROUTES), $live, 'Every declared route is registered.');

        foreach ($live as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('Route %s has no route-guard-manifest row.', $name));

            $row = $rows[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $can = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')));

            $this->assertSame($route->methods(), $row['methods'], sprintf('%s: methods drifted.', $name));
            $this->assertSame($middleware, $row['middleware'], sprintf('%s: middleware drifted.', $name));
            $this->assertSame(
                str_starts_with($name, 'student.') ? 'student' : 'admin',
                $row['panel'],
                sprintf('%s: panel.', $name),
            );
            $this->assertSame(
                array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [],
                $row['state_changing'],
                sprintf('%s: state_changing.', $name),
            );
            $this->assertSame(18, $row['owner_phase']);

            // Every route in this phase is guarded; none is public, so none carries a rationale.
            $this->assertNotEmpty($can, sprintf('%s has no can: guard at all.', $name));
            $this->assertNull($row['rationale'], sprintf('%s is guarded; a rationale would blur the two kinds of row.', $name));

            $literal = substr($can[0], 4);

            if (str_contains($literal, ',')) {
                // D87: `can:<ability>,<model>` is an ability on a model, so the row names both the
                // permission the policy requires and the method that also weighs the row's own state.
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
            if (($row['owner_phase'] ?? null) === 18) {
                $this->assertArrayHasKey($name, $live, sprintf('route-guard-manifest lists %s, which Phase 18 no longer registers.', $name));
            }
        }
    }

    /**
     * §6.1 and spine §2.18.2 — a waiver takes two abilities, and the manifest records which.
     *
     * `installments.change_status` says you may move this line; `fee_discounts.approve` says you may
     * reduce what the student owes. A waiver does both at once, so the first alone would let somebody
     * give money away one installment at a time. The route's `can:` carries the first; the policy
     * demands the second, which is why this is the phase's only two-ability write.
     */
    #[Test]
    public function waiving_is_the_one_write_that_needs_two_abilities(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $waive = $rows['admin.installments.waive'];

        $this->assertSame('installments.change_status', $waive['permission']);
        $this->assertSame('StudentFeeInstallmentPolicy::changeStatus', $waive['policy']);

        // The policy really does ask for the second one — asserted against the source, because a
        // manifest that merely claims it would be a comment.
        $policy = (string) file_get_contents(app_path('Policies/Institute/StudentFeeInstallmentPolicy.php'));

        $this->assertStringContainsString(
            'StudentFeeDiscountPolicy::MODULE',
            $policy,
            'changeStatus() must consult the discount module, not just the installment one.',
        );
    }

    /**
     * §6.5 — no route can edit or delete a discount, because neither act exists.
     *
     * Not "the policy says no" — there is no endpoint to say no to. A wrong discount is undone by a
     * reversal that points at it, and that needs `approve` rather than `edit`.
     */
    #[Test]
    public function no_route_can_edit_or_delete_a_discount_or_a_receipt(): void
    {
        foreach ($this->phase18Routes() as $name => $route) {
            $this->assertNotContains('DELETE', $route->methods(), sprintf('%s is a DELETE route.', $name));
            $this->assertStringEndsNotWith('.destroy', $name, sprintf('%s is named for a destroy action.', $name));
        }

        $rows = collect($this->manifest('route-guard-manifest'))->where('owner_phase', 18);

        // The only PUT in the phase is a plan rebuild, which replaces unpaid lines and keeps the paid
        // ones — it edits a schedule, never a money row.
        $puts = $rows->filter(static fn (array $row): bool => in_array('PUT', $row['methods'], true))->pluck('route')->values()->all();

        $this->assertSame(['admin.student-fees.installments.rebuild'], $puts);

        $this->assertSame(
            'fee_discounts.approve',
            $rows->firstWhere('route', 'admin.fee-discounts.reverse')['permission'],
            'Undoing a discount is an authority question, not a typo question.',
        );

        // §4.2: `fee_reminders` declares no edit and no delete at all.
        $declared = PermissionRegistry::permissionNames();

        $this->assertNotContains('fee_reminders.edit', $declared);
        $this->assertNotContains('fee_reminders.delete', $declared);
        $this->assertContains('fee_reminders.create', $declared);

        // §4.2 (Q2, F-6.9): the ability spine §6.6 row 6 asks for by name and never had.
        $this->assertContains('student_fee_payments.approve', $declared);
    }

    #[Test]
    public function every_phase_18_get_route_has_a_screen_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));

        $gets = array_filter(
            $this->phase18Routes(),
            static fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true),
        );

        foreach ($gets as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('GET route %s has no screen-manifest row.', $name));

            $row = $rows[$name];

            $this->assertContains($row['kind'], self::KINDS, sprintf('%s: unknown kind.', $name));
            $this->assertSame($guards[$name]['panel'], $row['panel'], sprintf('%s: the two manifests must agree on the panel.', $name));
            $this->assertSame(18, $row['owner_phase']);
            $this->assertIsCallable($row['params'], sprintf('%s: params is a closure.', $name));

            $expected = $guards[$name]['permission'] === null ? [] : [$guards[$name]['permission']];

            $this->assertSame($expected, $row['permissions'], sprintf('%s: the screen row must ask for what the route asks for.', $name));

            // A JSON or CSV answer is in neither HTML sweep; a print view has one fixed width but is
            // still read on screen before it reaches a printer.
            if ($row['response'] !== 'html') {
                $this->assertFalse($row['responsive'], sprintf('%s answers %s, not a page.', $name, $row['response']));
                $this->assertFalse($row['a11y'], sprintf('%s answers %s, not a page.', $name, $row['response']));
            } elseif ($row['kind'] === 'print') {
                $this->assertFalse($row['responsive'], sprintf('%s is laid out for paper.', $name));
                $this->assertTrue($row['a11y'], sprintf('%s is still HTML somebody reads on screen.', $name));
            } else {
                $this->assertTrue($row['responsive'], sprintf('%s is a page.', $name));
                $this->assertTrue($row['a11y'], sprintf('%s is a page.', $name));
            }
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 18) {
                $this->assertArrayHasKey($name, $gets, sprintf('screen-manifest lists %s, which is not a Phase 18 GET route.', $name));
            }
        }
    }

    /**
     * §9 — every student screen is owned, and no admin screen pretends to be.
     */
    #[Test]
    public function every_student_screen_carries_an_owner_and_no_admin_screen_does(): void
    {
        $rows = collect($this->manifest('screen-manifest'))->where('owner_phase', 18);

        $student = $rows->where('panel', 'student');

        $this->assertCount(4, $student, 'The student panel ships four screens.');

        foreach ($student as $row) {
            $this->assertSame('student_id', $row['idor']['owner'], sprintf('%s is owned by whoever is signed in.', $row['route']));
        }

        foreach ($rows->where('panel', 'admin') as $row) {
            $this->assertNull($row['idor']['owner'], sprintf('%s is an admin screen: the branch rule narrows it, not ownership.', $row['route']));
        }
    }

    /**
     * [D18-1] — the dedupe guard is on the generated column, not the nullable foreign key.
     *
     * MariaDB permits unlimited NULLs in a unique index, so a charge with no installment plan —
     * where `student_fee_installment_id` is genuinely NULL — would have slipped the guard on every
     * run and been chased every single night. That is the one failure the table exists to prevent.
     */
    #[Test]
    public function the_reminder_dedupe_guard_is_on_the_generated_column(): void
    {
        $manifest = $this->manifest('index-manifest');

        $this->assertArrayHasKey('student_fee_reminders', $manifest);

        $expected = ['student_fee_id', 'dedupe_line', 'type', 'due_date', 'offset_days'];

        $this->assertContains($expected, $manifest['student_fee_reminders'], 'The dedupe key is listed.');

        // And it is really UNIQUE, and really on the generated column.
        $columns = array_map(
            static fn (object $row): string => (string) $row->Column_name,
            DB::select('SHOW INDEX FROM `student_fee_reminders` WHERE Key_name = ?', ['uq_sfr_dedupe']),
        );

        $this->assertSame($expected, $columns);

        $unique = DB::select('SHOW INDEX FROM `student_fee_reminders` WHERE Key_name = ? AND Non_unique = 0', ['uq_sfr_dedupe']);

        $this->assertNotEmpty($unique, 'uq_sfr_dedupe must be UNIQUE, or the insert-first design guards nothing.');

        $generated = DB::selectOne(
            'SELECT GENERATION_EXPRESSION g, EXTRA e FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['student_fee_reminders', 'dedupe_line'],
        );

        $this->assertNotNull($generated, 'dedupe_line must exist.');
        $this->assertStringContainsString('coalesce', mb_strtolower((string) $generated->g));
        $this->assertStringContainsString('STORED', (string) $generated->e, 'A virtual column cannot be indexed uniquely here.');

        // D19: the table is an append-only log, so it carries neither column.
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('student_fee_reminders', 'deleted_at'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('student_fee_reminders', 'updated_at'));
    }

    /**
     * F-9.2 — every foreign key of the one table this phase creates leads an index.
     */
    #[Test]
    public function every_foreign_key_of_the_reminder_table_leads_an_index(): void
    {
        $manifest = $this->manifest('index-manifest');
        $leads = array_unique(array_map(static fn (array $c): string => $c[0], $manifest['student_fee_reminders']));

        $keys = DB::select(
            'SELECT COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['student_fee_reminders'],
        );

        foreach ($keys as $key) {
            $this->assertContains(
                (string) $key->c,
                $leads,
                sprintf('F-9.2: the foreign key student_fee_reminders.%s leads no index-manifest row.', $key->c),
            );
        }

        // And every listed entry is really the leading columns of a real index.
        $real = [];

        foreach (DB::select('SHOW INDEX FROM `student_fee_reminders`') as $row) {
            $real[$row->Key_name][(int) $row->Seq_in_index] = (string) $row->Column_name;
        }

        foreach ($real as &$columns) {
            ksort($columns);
            $columns = array_values($columns);
        }

        unset($columns);

        foreach ($manifest['student_fee_reminders'] as $want) {
            $found = false;

            foreach ($real as $columns) {
                if (array_slice($columns, 0, count($want)) === $want) {
                    $found = true;

                    break;
                }
            }

            $this->assertTrue($found, sprintf('index-manifest lists (%s), which no index leads with.', implode(', ', $want)));
        }
    }

    /**
     * §6.9 / PH18-37 — no service of this phase writes a commission, a wallet or a payment row.
     *
     * A static scan, because the claim is about the whole surface rather than about one path: money in
     * and money out go through the spine's `PaymentService`, and commission happens in the spine's
     * jobs. `transferPayment()` is the one method that reaches `PaymentService`, and it does so by
     * delegation — it writes no receipt of its own.
     */
    #[Test]
    public function no_institute_service_writes_a_commission_or_a_payment_row(): void
    {
        $forbidden = [
            'collaborator_commission_ledger_entries',
            'collaborator_commission_entitlements',
            'collaborator_wallets',
            'StudentCommissionService',
            'CommissionReversalService',
            'LedgerWriter',
        ];

        foreach (glob(app_path('Services/Institute/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $stripped = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $stripped,
                    sprintf('%s reaches into the commission engine (§6.9).', basename($file)),
                );
            }

            // INV-26: no service sums the ledger itself.
            $this->assertDoesNotMatchRegularExpression(
                '/SUM\(\s*[`\']?signed_amount/i',
                $stripped,
                sprintf('%s computes a wallet figure of its own (INV-26).', basename($file)),
            );
        }
    }

    #[Test]
    public function this_phase_accepts_no_upload_at_all(): void
    {
        $uploads = $this->manifest('upload-manifest');

        $this->assertCount(0, collect($uploads)->where('owner_phase', 18));

        // Nothing in this phase takes a file. The fee slip and the receipt are generated, not
        // uploaded, and the importer §7 might have wanted does not exist here either.
        foreach ($this->phase18Routes() as $name => $route) {
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
    private function phase18Routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (in_array($name, self::ROUTES, true)) {
                $routes[$name] = $route;
            }
        }

        ksort($routes);

        return $routes;
    }
}
