<?php

declare(strict_types=1);

namespace Tests\Feature\Isolation;

use App\Enums\ClientStatus;
use App\Enums\PanelType;
use App\Models\Crm\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * ISO-01 — the five-panel entry matrix (phase-24-25 section 11.3, requirement §112).
 *
 * The file is named as section 11.3 names it. ISO-02 … ISO-12 live next door in `TenantScopeTest`
 * and `HorizontalEscalationTest` rather than here: the contract puts all twelve ids under one
 * filename, but ISO-01 asks a question about **doors** and needs only roles and panels, while the
 * rest ask about **rows** and drag in the financial, invoice and schedule fixtures. Keeping them
 * apart means a door regression fails in a file that builds nothing and cannot fail for a fixture's
 * reasons. The method names are the contract's, exactly, which is what section 11's preamble pins.
 *
 * **One identity, five doors, and the door is chosen by `roles.panel` — never by rank.** Without this
 * matrix the failure mode is silent and total: a panel route group registered with the wrong `panel:`
 * argument, or a role seeded onto the wrong panel, lets a student open the collaborator dashboard and
 * read another person's money. Nothing else in the suite would notice, because every panel's own tests
 * sign in as a user of that panel and therefore never ask the question this file asks.
 *
 * The matrix is **22 users x 5 panels = 110 cells**, and every cell is an exact HTTP status:
 *
 *   - one user per seeded role (18 of them, read from the `roles` table, never spelled out here —
 *     `CLAUDE.md` §1.8: a test that hardcodes a role name stops noticing when the seeder changes), plus
 *   - a second user for each of the four portal roles, so "A reaches the collaborator panel" is never
 *     satisfied by "there is only one collaborator".
 *
 * Two cells are worth naming on their own, because both are the sort of thing a reasonable person
 * would "fix" into a bug:
 *
 *   - **Super Admin reaches `/admin` and is 403 on all four portals.** A Super Admin is not a
 *     collaborator. `Gate::before` hands them every *permission*, and panel entry is deliberately
 *     decided before any permission is consulted (`EnsurePanelAccess` -> `User::canAccessPanel()`), so
 *     rank buys nothing here. Opening the portals "for support" would put an admin inside a tenant's
 *     session with that tenant's scope, which is precisely the boundary §112 exists to draw.
 *   - **A Client-role user needs a bound, portal-enabled `clients` row to score 200 on `/client`.**
 *     `client.context` (D31) re-resolves the binding on every request and answers **403** when it
 *     fails — the same status as a cross-panel refusal. Without the binding the own-panel cell would
 *     be 403 for the right reason and this test would pass for the wrong one, so the fixture binds it
 *     and the test would then fail loudly if panel entry itself regressed.
 *
 * Section 11.3 also requires every non-2xx cell to assert that **nothing was written**. A refused door
 * that still logs the visitor into a business table is a write primitive; {@see assertNothingWritten()}
 * pins a list of tables that a panel *entry* must never touch.
 */
#[Group('isolation')]
final class PanelMatrixTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * The number of roles the seeder ships (phase-01 §5). Asserted rather than assumed: the matrix's
     * "110 cells" claim is only true while this holds, and a nineteenth role must arrive with a
     * decision about which panel it opens — not silently widen the fixture.
     */
    private const SEEDED_ROLE_COUNT = 18;

    /** 18 roles + a second user for each of the four portal roles. */
    private const SEAT_COUNT = 22;

    /**
     * Tables a GET on a panel home must never write to, whatever the answer was.
     *
     * `sessions` and `activity_log` are deliberately absent: a request legitimately touches both, and
     * pinning them would make this assertion a false alarm rather than a guard.
     *
     * @var list<string>
     */
    private const MUST_NOT_GROW = [
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'modules',
        'clients',
        'students',
        'projects',
        'invoices',
        'student_fee_payments',
        'collaborator_commission_ledger_entries',
        'collaborator_payouts',
    ];

    /**
     * The five doors, taken from the enum that owns them so a sixth panel cannot be added without
     * this matrix growing with it.
     *
     * @return array<string, array{0: string}>
     */
    public static function panelProvider(): array
    {
        $cases = [];

        foreach (PanelType::cases() as $panel) {
            $cases[$panel->value] = [$panel->value];
        }

        return $cases;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-01 — the matrix
    |--------------------------------------------------------------------------
    */

    /**
     * One panel per invocation, all 22 seats against it: 5 x 22 = 110 cells.
     *
     * The provider is static (it may not touch the database), so the seats are built inside the test.
     * Failures are collected and reported together rather than thrown on the first one: "Student B is
     * 200 on /collaborator" and "Accountant is 403 on /admin" are different bugs, and finding them one
     * run at a time is how a matrix stops being run.
     */
    #[Test]
    #[DataProvider('panelProvider')]
    public function test_panel_entry_matrix(string $panel): void
    {
        $target = PanelType::from($panel);
        $path = route($target->homeRoute(), absolute: false);

        $seats = $this->seats();

        $this->assertCount(
            self::SEAT_COUNT,
            $seats,
            sprintf('The matrix is defined as %d seats x 5 panels = 110 cells.', self::SEAT_COUNT),
        );

        $before = $this->rowCounts();
        $failures = [];

        foreach ($seats as $label => [$user, $ownPanel]) {
            $expected = $ownPanel === $panel ? 200 : 403;

            $actual = $this->actingAs($user)->get($path)->getStatusCode();

            if ($actual !== $expected) {
                $failures[] = sprintf(
                    '%s -> %s: expected %d, got %d.',
                    $label,
                    $path,
                    $expected,
                    $actual,
                );
            }

            if ($expected === 403) {
                $this->assertNothingWritten($before, sprintf('%s was refused %s', $label, $path));
            }
        }

        $this->assertSame(
            [],
            $failures,
            sprintf(
                "The %s panel door answered the wrong status for %d of %d seats:\n  %s",
                $panel,
                count($failures),
                count($seats),
                implode("\n  ", $failures),
            ),
        );
    }

    /**
     * The fixture's own contract. If the seeder ships a nineteenth role, or a role's `panel` column
     * changes, the matrix silently stops being the matrix the contract describes — so it is asserted
     * before the cells are, and the failure names the arithmetic rather than a status code.
     */
    #[Test]
    public function test_the_matrix_is_twenty_two_seats_over_eighteen_seeded_roles(): void
    {
        $roles = $this->seededRoles();

        $this->assertCount(
            self::SEEDED_ROLE_COUNT,
            $roles,
            'phase-24-25 section 11.3 sizes ISO-01 at 18 seeded roles; RoleSeeder now ships a different number.',
        );

        $portalRoles = $roles->filter(
            static fn (Role $role): bool => self::panelOf($role) !== PanelType::Admin->value
        );

        $this->assertCount(
            4,
            $portalRoles,
            'Exactly four roles live outside the staff panel: Collaborator, Student, Teacher, Client.',
        );

        $this->assertSame(
            self::SEAT_COUNT,
            $roles->count() + $portalRoles->count(),
            '22 seats = one user per role, plus a second user for each portal role.',
        );

        $this->assertSame(
            110,
            self::SEAT_COUNT * count(PanelType::cases()),
            'The matrix is 110 cells.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rank, and the two-panel user
    |--------------------------------------------------------------------------
    */

    /**
     * Stated separately from the matrix because it is the single cell most likely to be "fixed".
     */
    #[Test]
    public function test_super_admin_reaches_admin_and_is_forbidden_on_all_four_portals(): void
    {
        $user = $this->createSuperAdmin();

        $this->actingAs($user)->get(route('admin.dashboard', absolute: false))->assertOk();

        foreach (PanelType::cases() as $panel) {
            if ($panel === PanelType::Admin) {
                continue;
            }

            $this->actingAs($user)
                ->get(route($panel->homeRoute(), absolute: false))
                ->assertForbidden();
        }
    }

    /**
     * Two panel roles open exactly two doors — and `primaryPanel()` picks one of them the same way
     * every time.
     *
     * Determinism is the load-bearing half. `primaryPanel()` decides where a login lands, and it sorts
     * by `roles.level` with the role id as the tie-break. A non-deterministic answer would send the
     * same person to a different panel on alternate logins, which reads as an intermittent permission
     * bug and is almost impossible to reproduce on purpose. Teacher (level 50) outranks Collaborator
     * (level 60), so Teacher wins **regardless of the order the roles were assigned in** — which is
     * what the second assignment below actually tests.
     */
    #[Test]
    public function test_a_user_holding_two_panel_roles_reaches_exactly_those_two(): void
    {
        $teacherFirst = $this->createUserWithRole('Teacher');
        $teacherFirst->assignRole('Collaborator');
        $this->forgetPermissionCache();
        $teacherFirst = $teacherFirst->fresh();

        $collaboratorFirst = $this->createUserWithRole('Collaborator');
        $collaboratorFirst->assignRole('Teacher');
        $this->forgetPermissionCache();
        $collaboratorFirst = $collaboratorFirst->fresh();

        foreach ([$teacherFirst, $collaboratorFirst] as $user) {
            $this->assertInstanceOf(User::class, $user);

            $reached = [];

            foreach (PanelType::cases() as $panel) {
                $status = $this->actingAs($user)
                    ->get(route($panel->homeRoute(), absolute: false))
                    ->getStatusCode();

                if ($status === 200) {
                    $reached[] = $panel->value;

                    continue;
                }

                $this->assertSame(403, $status, sprintf('%s must answer 403, not %d.', $panel->value, $status));
            }

            sort($reached);

            $this->assertSame(
                [PanelType::Collaborator->value, PanelType::Teacher->value],
                $reached,
                'A teacher who is also a collaborator reaches those two panels and no others.',
            );
        }

        // Same two roles, opposite assignment order, same landing panel: the level column decides,
        // not the pivot's insertion order.
        $this->assertSame(
            PanelType::Teacher,
            $teacherFirst->primaryPanel(),
            'Teacher (level 50) outranks Collaborator (level 60).',
        );

        $this->assertSame(
            $teacherFirst->primaryPanel(),
            $collaboratorFirst->primaryPanel(),
            'primaryPanel() must not depend on the order the roles were assigned in.',
        );

        // And it must not depend on the order the relation happens to load in either.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(
                PanelType::Teacher,
                User::query()->findOrFail($teacherFirst->getKey())->primaryPanel(),
                'primaryPanel() answered differently on a fresh load.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * The 22 seats, labelled so a failure message names the person rather than an index.
     *
     * @return array<string, array{0: User, 1: string}>
     */
    private function seats(): array
    {
        $seats = [];

        foreach ($this->seededRoles() as $role) {
            $name = (string) $role->name;
            $panel = self::panelOf($role);

            $seats[$name.' (A)'] = [$this->seatUser($name, $panel), $panel];

            // A second tenant for every portal role: "A reaches the collaborator panel" must not be
            // true merely because A is the only collaborator in the fixture.
            if ($panel !== PanelType::Admin->value) {
                $seats[$name.' (B)'] = [$this->seatUser($name, $panel), $panel];
            }
        }

        return $seats;
    }

    /**
     * One seat: a user holding exactly that seeded role, plus whatever binding the panel's own
     * middleware insists on before it will answer 200.
     */
    private function seatUser(string $role, string $panel): User
    {
        $user = $this->createUserWithRole($role);

        if ($panel === PanelType::Client->value) {
            $this->bindClientPortal($user);
        }

        return $user;
    }

    /**
     * A portal-enabled `clients` row bound to this user.
     *
     * Only the client panel needs this. `client.context` runs on every `/client` route and 403s when
     * the binding is missing (D31) — the other three portals resolve their tenant row inside the
     * controller, and their phase-01 dashboards render without one.
     */
    private function bindClientPortal(User $user): void
    {
        $client = new Client;

        $client->forceFill([
            'client_code' => 'CL-ISO'.str_pad((string) $user->getKey(), 5, '0', STR_PAD_LEFT),
            'name' => 'Isolation Client '.$user->getKey(),
            'company_name' => 'Isolation Client '.$user->getKey(),
            'email' => sprintf('iso-client-%d@example.test', $user->getKey()),
            'status' => ClientStatus::Active->value,
            'portal_enabled' => true,
            'user_id' => $user->getKey(),
        ])->save();
    }

    /**
     * The seeded roles, ordered so the fixture is built the same way on every run.
     *
     * @return Collection<int, Role>
     */
    private function seededRoles()
    {
        return Role::query()
            ->where('guard_name', (string) config('auth.defaults.guard', 'web'))
            ->orderBy('level')
            ->orderBy('id')
            ->get();
    }

    /**
     * `roles.panel` as a plain string, whether the model casts it to the enum or not.
     */
    private static function panelOf(Role $role): string
    {
        $panel = $role->panel;

        if ($panel instanceof PanelType) {
            return $panel->value;
        }

        return (string) $panel;
    }

    /*
    |--------------------------------------------------------------------------
    | "and nothing was written"
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::MUST_NOT_GROW as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $before
     */
    private function assertNothingWritten(array $before, string $context): void
    {
        foreach ($this->rowCounts() as $table => $after) {
            $this->assertSame(
                $before[$table] ?? $after,
                $after,
                sprintf('%s, and yet `%s` gained a row.', $context, $table),
            );
        }
    }
}
