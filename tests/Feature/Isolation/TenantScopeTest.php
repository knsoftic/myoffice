<?php

declare(strict_types=1);

namespace Tests\Feature\Isolation;

use App\Enums\ClientStatus;
use App\Enums\PanelType;
use App\Enums\PayoutMethod;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Crm\Client;
use App\Models\Institute\Student;
use App\Models\Institute\Teacher;
use App\Models\Support\Conversation;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use App\Services\Institute\StudentService;
use App\Services\Support\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * ISO-02 … ISO-12 — tenant scoping across the four portals (phase-24-25 section 11.3).
 *
 * ISO-01 proves the *door* is the right one. This file proves that once somebody is through their own
 * door, the rows they can reach are theirs. **The two failures are independent**: a correctly gated
 * panel that answers a foreign id with 200 leaks exactly as much as an ungated one, and every portal
 * controller here resolves its tenant from the session — `ClientContext`, `ResolvesTheSignedInStudent`,
 * `ResolvesTheSignedInTeacher`, `ResolvesOwnCollaborator` — so a regression is usually one forgotten
 * `where()` rather than a missing middleware.
 *
 * **404, never 403, on a foreign id.** A 403 confirms the row exists, which turns an id into something
 * worth guessing and turns a sequential primary key into a row count the business did not publish.
 * Every portal controller in this system states that rule in its own docblock; this file is where the
 * four statements are checked against each other. Where a route answers 403 instead, it is because the
 * *ability* is absent rather than the row — a different question, and section 11.4 keeps the two apart.
 *
 * Fixtures come from the phases that own the tables, through the services that own the writes: a row
 * inserted by hand would test a shape the application never produces (`BuildsFinancialFixtures`,
 * `BuildsInvoiceFixtures`, `BuildsSchedules`). The one exception is the tenant→login binding, which is
 * written with the query builder exactly as `CollaboratorPanelTest` does: `collaborators.user_id`,
 * `students.user_id` and `teachers.user_id` are deliberately not mass assignable (D2), and a fixture
 * that could set them through the model would be proving the guard is missing.
 *
 * Three cells of section 11.3 are **skipped with a named reason** rather than faked; see the skip
 * messages on `test_branch_scoping`, `test_pipeline_visibility_scope` and
 * `test_the_collaborator_global_scope_is_registered_on_every_owned_model`. Each names the fixture or
 * the class that does not exist yet, and each was re-checked before being left skipped.
 *
 * **A skip is a claim, and a claim gets verified.** ISO-10 was skipped here on the stated ground that
 * "no search route is registered", which one `php artisan route:list` falsifies: `admin.search.index`,
 * `admin.search.open` and `admin.search.suggest` have existed since phase-19-23 §7.8, `global_search`
 * is `is_core = true` so it can never be disabled, and `RoleSeeder` puts it in `$staffBase`, which all
 * fourteen staff roles merge. The cell is now written properly against `admin.search.index`. A skip
 * whose reason a reader can disprove in one command is worse than a failing test, because a failure
 * asks to be fixed and a skip closes the cell.
 *
 * Every method name below is the one section 11.3 gives, exactly — the preamble to section 11 makes
 * each id "a real test method name prefix", so a tidier name is a requirement that, read back out of
 * the evidence pack, nobody wrote.
 */
#[Group('isolation')]
final class TenantScopeTest extends TestCase
{
    // `setting()` is declared by both BuildsFinancialFixtures and (through BuildsAdmissions)
    // BuildsCatalogue. The two bodies are equivalent, so the conflict is resolved rather than worked
    // around: the financial one wins because this file's money settings are the ones that decide
    // whether a ledger row exists at all, and the catalogue copy stays reachable under a second name
    // so nothing in BuildsSchedules silently loses its settings helper.
    use BuildsFinancialFixtures, BuildsInvoiceFixtures, BuildsSchedules {
        BuildsFinancialFixtures::setting insteadof BuildsSchedules;
        BuildsSchedules::setting as catalogueSetting;
    }
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Column names that must not appear in a portal response body.
     *
     * Deliberately only the snake_case identifiers. The contract also names `budget`, but "Budget" is
     * an ordinary English word that a legitimate label may contain, and an assertion that fails on a
     * heading is an assertion somebody deletes. The identifiers below cannot occur by accident: if one
     * is in the body, a model was serialised whole.
     *
     * @var list<string>
     */
    private const COMMISSION_COLUMNS = [
        'collaborator_id',
        'collaborator_referral_id',
        'commission_state',
        'commission_skip_reason',
        'commission_skip_detail',
        'commission_rate',
        'base_amount',
    ];

    /**
     * ISO-10's one query string.
     *
     * Deliberately a word that occurs nowhere in the seeders, the demo data or this suite's other
     * fixtures: the test compares result *sets*, so a token that a seeded row happened to match would
     * add rows nobody planted and turn a subset assertion into a coin toss.
     */
    private const SEARCH_TOKEN = 'Zarnishol';

    /** ISO-11's opening message — the string that must not reach a colleague's inbox. */
    private const THREAD_PROBE = 'Isolation thread probe.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        // The commission engine has to actually post, or "A cannot see B's ledger row" is satisfied by
        // there being no ledger rows. These are the same settings CollaboratorPanelTest fixes.
        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'automatic');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('finance.backdate_limit_days', 3650);
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-02 — the client panel
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-02 — Client A over the invoice screens: own rows only, B's id is a 404, and the body carries
     * none of the internal columns.
     *
     * The invoice is **issued** rather than left a draft on purpose: `client.invoices.*` filters
     * `status <> draft`, so a draft would 404 for the owner too and the test would pass without ever
     * reaching the ownership check.
     */
    #[Test]
    public function test_client_panel_isolation(): void
    {
        [$clientA, $userA] = $this->clientWithLogin('Alpha Holdings');
        [$clientB] = $this->clientWithLogin('Beta Traders');

        $invoiceA = $this->issuedInvoice($clientA);
        $invoiceB = $this->issuedInvoice($clientB);

        $index = $this->actingAs($userA)
            ->get(route('client.invoices.index', absolute: false))
            ->assertOk();

        $index->assertSee((string) $invoiceA->invoice_number);
        $index->assertDontSee((string) $invoiceB->invoice_number);

        $own = $this->actingAs($userA)
            ->get(route('client.invoices.show', $invoiceA, absolute: false))
            ->assertOk();

        $this->assertBodyOmits($own->getContent(), self::COMMISSION_COLUMNS, 'the client invoice detail');
        $this->assertBodyOmits($own->getContent(), ['estimated_hours', 'actual_hours'], 'the client invoice detail');

        // The whole point: B's id, as A.
        $this->actingAs($userA)
            ->get(route('client.invoices.show', $invoiceB, absolute: false))
            ->assertNotFound();

        $this->actingAs($userA)
            ->get(route('client.invoices.pdf', $invoiceB, absolute: false))
            ->assertNotFound();

        // An id walk finds nothing either — including ids that do not exist, which must be
        // indistinguishable from ids that do.
        $highest = (int) $invoiceB->getKey();

        for ($id = 1; $id <= $highest + 5; $id++) {
            if ($id === (int) $invoiceA->getKey()) {
                continue;
            }

            $this->actingAs($userA)
                ->get(route('client.invoices.show', $id, absolute: false))
                ->assertNotFound();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-03 — the student panel
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-03 — Student A over their own fees; B's charge is a 404 and no commission column ships.
     *
     * `Student\FeeController` selects an explicit column list rather than hiding columns in the view,
     * because a view that does not *print* a column still ships it in the payload. That is what the
     * body assertion here is for: it would catch `select(self::VISIBLE)` being replaced by `all()`
     * even though every screen still looked right.
     */
    #[Test]
    public function test_student_panel_isolation(): void
    {
        $partner = $this->partner('10.0000');

        [$studentA, $userA] = $this->studentWithLogin();
        [$studentB] = $this->studentWithLogin();

        $chargeA = $this->charge(null, '30000.00', ['student_id' => $studentA->getKey()]);

        // B's charge is referred, so B's rows carry the commission columns A must never see.
        $chargeB = $this->charge($partner, '30000.00', ['student_id' => $studentB->getKey()]);
        $this->receive($chargeB, '10000.00', ['on' => '2026-02-10']);

        $index = $this->actingAs($userA)
            ->get(route('student.fees.index', absolute: false))
            ->assertOk();

        $index->assertSee((string) $chargeA->fee_number);
        $index->assertDontSee((string) $chargeB->fee_number);
        $this->assertBodyOmits($index->getContent(), self::COMMISSION_COLUMNS, 'the student fee list');

        $own = $this->actingAs($userA)
            ->get(route('student.fees.show', $chargeA, absolute: false))
            ->assertOk();

        $this->assertBodyOmits($own->getContent(), self::COMMISSION_COLUMNS, 'the student fee detail');

        foreach (['student.fees.show', 'student.fees.slip'] as $name) {
            $this->actingAs($userA)
                ->get(route($name, $chargeB, absolute: false))
                ->assertNotFound();
        }

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-04 — the collaborator panel
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-04 — Partner A over the money screens. B's entry is a 404, B's figures are absent, and both
     * wallets still reconcile after the sweep.
     *
     * `assertWalletMatchesLedger()` on **both** partners is not ceremony: the cheapest way to make an
     * isolation test pass is to scope a query so hard that it returns nothing, and a wallet that no
     * longer matches its ledger is how that shows up.
     */
    #[Test]
    public function test_collaborator_panel_isolation(): void
    {
        [$partnerA, $userA, $entryA] = $this->partnerWithLogin();
        [$partnerB, , $entryB] = $this->partnerWithLogin();

        $this->assertNotSame((int) $entryA->getKey(), (int) $entryB->getKey());

        $window = ['from' => '2020-01-01', 'to' => now()->toDateString()];

        $index = $this->actingAs($userA)
            ->get(route('collaborator.commissions.index', $window, absolute: false))
            ->assertOk();

        // `reference` is the derived `CLE-{id}` (it is not stored: a second column holding the same
        // fact is a second thing that can disagree with the primary key). The two entries are created
        // one after the other, so their ids are adjacent and neither string can be a prefix of the
        // other — which is what makes a plain substring assertion safe here.
        $index->assertSee((string) $entryA->reference);
        $index->assertDontSee((string) $entryB->reference);

        $this->actingAs($userA)
            ->get(route('collaborator.commissions.show', $entryA, absolute: false))
            ->assertOk();

        $this->actingAs($userA)
            ->get(route('collaborator.commissions.show', $entryB, absolute: false))
            ->assertNotFound();

        $statement = $this->actingAs($userA)
            ->get(route('collaborator.statement.index', $window, absolute: false))
            ->assertOk();

        $statement->assertDontSee((string) $entryB->reference);

        $this->actingAs($userA)
            ->get(route('collaborator.wallet.index', absolute: false))
            ->assertOk()
            ->assertDontSee((string) $partnerB->collaborator_code);

        $this->assertWalletMatchesLedger($partnerA);
        $this->assertWalletMatchesLedger($partnerB);
    }

    /**
     * ISO-12, the half of it this phase can prove without a browser: an **export** carries the screen's
     * scope, not the table's.
     *
     * Exports are where scopes are lost most often, because the export path is usually a second query
     * written next to the first one rather than the same query reused. A CSV that contains a row the
     * screen withheld is a silent, file-shaped data breach.
     *
     * **The print half of ISO-12 is not asserted here and the name says both on purpose.** Section
     * 11's preamble makes every id a real method name, so shortening it to `test_exports_…` would
     * close the cell by renaming it. What is missing is the branch-scoped Institute Manager and the
     * nine print views of section 11.6 RSP-06, which need a two-branch fixture (the same one
     * {@see test_branch_scoping()} is skipped for). The CSV branch below is real and runs.
     */
    #[Test]
    public function test_exports_and_prints_carry_the_same_scope_as_the_screen(): void
    {
        [$partnerA, $userA] = $this->partnerWithLogin();
        [, , $entryB] = $this->partnerWithLogin();

        $response = $this->actingAs($userA)->get(route('collaborator.statement.export', [
            'format' => 'csv',
            'from' => '2020-01-01',
            'to' => now()->toDateString(),
        ], absolute: false));

        $response->assertOk();

        // `StatementController::export()` returns a StreamedResponse for `csv`, whose `getContent()` is
        // `false` rather than a string — read the stream, and fall back only if a later change makes
        // the export a plain response.
        $csv = $response->streamedContent();
        $csv = $csv !== '' ? $csv : (string) $response->getContent();

        $this->assertStringNotContainsString(
            (string) $entryB->reference,
            $csv,
            'The statement export leaked another partner’s ledger reference.',
        );

        $this->assertWalletMatchesLedger($partnerA);
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-05 — the money columns of the collaborator panel
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-05 — a figure a partner may not see is **absent from the body**, not hidden by the view.
     *
     * The distinction is the whole test. Every one of these screens renders correctly with the column
     * dropped, so the tempting implementation is a `@can` around a `<td>` — and a `@can` around a `<td>`
     * still ships the number in the serialised model, in the Alpine state, and in the printed page
     * source. `CommissionController::visiblePurposes()` narrows the **query** instead, so a project-only
     * partner's student commission never enters the result set; this test is what stops that being
     * refactored back into a view concern.
     *
     * **The setting is not the permission** (spine FT-48). `collaborator.payout_request_enabled` opens
     * the feature for the business; `collaborator_portal.payout_request` decides who holds it, and the
     * seeded Collaborator role deliberately does not. Turning the setting on below and still expecting
     * 403 is the assertion that keeps the two from collapsing into one.
     *
     * Three of the contract's six clauses run here. **The project ones — `.project_value`,
     * `.project_payments`, `.project_client` — are not asserted**, because no fixture under `tests/`
     * builds a referred project with a received payment (`BuildsFinancialFixtures` stops at student
     * fees), and this slice may not add one to another phase's concerns directory. They are named here
     * rather than left to be inferred from a method that quietly covers half its title. Owner: whoever
     * ships `tests/Feature/Financial/Concerns/BuildsProjectFixtures.php`.
     */
    #[Test]
    public function test_collaborator_money_columns_are_permission_gated(): void
    {
        // The business has switched payout requests on for everybody. The permission must still decide.
        $this->setting('collaborator.payout_request_enabled', true);

        [$partner, , $entry] = $this->partnerWithLogin();

        // A partner who refers projects, not students: they hold the *other* commission permission, so
        // the list route opens and the question becomes what is inside it.
        $projectOnly = $this->createUserWithPermissions([
            'collaborator_portal.dashboard',
            'collaborator_portal.wallet',
            'collaborator_portal.payouts',
            'collaborator_portal.project_commission',
        ], PanelType::Collaborator, 60);

        DB::table('collaborators')->where('id', $partner->getKey())->update(['user_id' => $projectOnly->getKey()]);

        $window = ['from' => '2020-01-01', 'to' => now()->toDateString()];

        $index = $this->actingAs($projectOnly)
            ->get(route('collaborator.commissions.index', $window, absolute: false))
            ->assertOk();

        // The ledger reference, not the rupee figure. `CLE-{id}` identifies exactly one row and can
        // appear on this page for exactly one reason; `4000.00` is a number that a wallet chip in the
        // shell, a filter default or an unrelated total could put on the page honestly, and an
        // assertion that fails on the shell is an assertion somebody deletes rather than reads.
        $index->assertDontSee((string) $entry->reference);

        // Not merely unlisted: unreachable, and unreachable the same way somebody else's row is.
        $this->actingAs($projectOnly)
            ->get(route('collaborator.commissions.show', $entry, absolute: false))
            ->assertNotFound();

        // Without `.statement_download` the statement is a 403 — an absent ability, not an absent row.
        $this->actingAs($projectOnly)
            ->get(route('collaborator.statement.index', $window, absolute: false))
            ->assertForbidden();

        // And without `.payout_request`, with the setting on, a request is still refused.
        $this->actingAs($projectOnly)
            ->post(route('collaborator.payouts.store', absolute: false), [
                'amount' => '1000.00',
                'method' => PayoutMethod::BankTransfer->value,
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            (int) DB::table('collaborator_payouts')->count(),
            'A refused payout request still wrote a row.',
        );

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-06 — the teacher panel
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-06 — Teacher A sees only batches whose `teacher_id` is theirs; B's batch is a 404.
     */
    #[Test]
    public function test_teacher_panel_isolation(): void
    {
        [$teacherA, $userA] = $this->teacherWithLogin();
        [$teacherB] = $this->teacherWithLogin();

        // Explicit codes rather than the fixture's `BATCH-{n}`: that sequence is not zero-padded, so
        // `BATCH-1` is a prefix of `BATCH-10` and `assertDontSee()` — a plain substring search — would
        // report a leak that is not there (or, worse, miss one that is).
        $batchA = $this->batch(null, ['teacher_id' => $teacherA->getKey(), 'code' => 'BATCH-ISO-ALPHA']);
        $batchB = $this->batch(null, ['teacher_id' => $teacherB->getKey(), 'code' => 'BATCH-ISO-BETA']);

        $index = $this->actingAs($userA)
            ->get(route('teacher.batches.index', absolute: false))
            ->assertOk();

        $index->assertSee((string) $batchA->code);
        $index->assertDontSee((string) $batchB->code);

        $this->actingAs($userA)
            ->get(route('teacher.batches.show', $batchA, absolute: false))
            ->assertOk();

        $this->actingAs($userA)
            ->get(route('teacher.batches.show', $batchB, absolute: false))
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-07 — staff roles are scoped too
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-07 — the five staff roles the contract names, each against the doors it may and may not open.
     *
     * **Every role here is on the same panel**, so `panel:admin` decides nothing and the only thing
     * standing between a Receptionist and the payroll is a `can:` on a route. That is a one-line guard,
     * and a one-line guard is what a refactor drops. Section 11.3 asks each assertion to *name the
     * permission that is absent*, which is what turns a red test into an instruction.
     *
     * The positive cells matter as much as the refusals: a role that is 403 everywhere would satisfy
     * every "may not" below and be completely broken. Each role is therefore given at least one door it
     * must open, and the 200 is asserted first.
     *
     * Two clauses of the contract's ISO-07 are covered elsewhere rather than duplicated here:
     * "cannot approve a payout they created" is FIN-14's separation-of-duties case (section 11.5.2,
     * which owns the payout lifecycle), and "may create a receipt and an inquiry" is PH18's HTTP-level
     * suite. What is asserted here is the part no other file asks: the matrix of doors.
     */
    #[Test]
    public function test_staff_role_scoping(): void
    {
        $matrix = [
            // Front desk: takes fee money, never decides what it means.
            'Receptionist' => [
                'may' => ['admin.students.index', 'admin.course-inquiries.index', 'admin.fee-payments.index'],
                'mayNot' => [
                    'admin.payroll-runs.index' => 'payroll.view_any',
                    'admin.commissions.index' => 'collaborator_commissions.view_any',
                    'admin.wallets.index' => 'collaborator_wallets.view_any',
                    'admin.payouts.index' => 'collaborator_payouts.view_any',
                    'admin.expenses.index' => 'expenses.view_any',
                ],
            ],
            // Runs the classroom; the money around it is somebody else's.
            'Course Coordinator' => [
                'may' => ['admin.batches.index', 'admin.courses.index'],
                'mayNot' => [
                    'admin.commissions.index' => 'collaborator_commissions.view_any',
                    'admin.payouts.index' => 'collaborator_payouts.view_any',
                    'admin.wallets.index' => 'collaborator_wallets.view_any',
                ],
            ],
            // People, not students and not invoices.
            'HR' => [
                'may' => ['admin.employees.index'],
                'mayNot' => [
                    'admin.students.index' => 'students.view_any',
                    'admin.invoices.index' => 'invoices.view_any',
                    'admin.student-fees.index' => 'student_fees.view_any',
                ],
            ],
            // Reads money everywhere — and the pipeline is not money.
            'Accountant' => [
                'may' => ['admin.invoices.index', 'admin.expenses.index', 'admin.student-fees.index'],
                // The lead index is gated on `leads.view`, not `view_any` (phase-05 [D-P5-8] narrows
                // *within* the screen rather than at the door), so that is the name printed here.
                'mayNot' => [
                    'admin.leads.index' => 'leads.view',
                ],
            ],
            // The whole pipeline, and nothing that pays anybody.
            'Sales Executive' => [
                'may' => ['admin.leads.index'],
                'mayNot' => [
                    'admin.payroll-runs.index' => 'payroll.view_any',
                    'admin.student-fees.index' => 'student_fees.view_any',
                ],
            ],
        ];

        $failures = [];

        foreach ($matrix as $role => $doors) {
            $user = $this->createUserWithRole($role);

            foreach ($doors['may'] as $name) {
                $status = $this->actingAs($user)->get(route($name, absolute: false))->getStatusCode();

                if ($status !== 200) {
                    $failures[] = sprintf(
                        '%s must reach %s and got %d — the role has lost a permission it does its job with.',
                        $role,
                        $name,
                        $status,
                    );
                }
            }

            foreach ($doors['mayNot'] as $name => $permission) {
                $status = $this->actingAs($user)->get(route($name, absolute: false))->getStatusCode();

                if ($status !== 403) {
                    $failures[] = sprintf(
                        '%s reached %s with %d. It holds no `%s`, so the route has lost its `can:`.',
                        $role,
                        $name,
                        $status,
                        $permission,
                    );
                }
            }
        }

        // Collected rather than thrown one at a time: "HR reads students" and "Sales reads payroll" are
        // different bugs, and a matrix that reports one per run is a matrix nobody runs twice.
        $this->assertSame(
            [],
            $failures,
            sprintf("The staff scoping matrix broke in %d places:\n  - %s", count($failures), implode("\n  - ", $failures)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-11 — notifications and messages are personal
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-11 — a notification belongs to its `notifiable`, and a conversation belongs to its
     * **participants**.
     *
     * `notifications.id` is a **uuid**, so the first half is not an id walk — which is exactly why the
     * ownership predicate is easy to forget. An unguessable id is not an authorisation check: the id
     * travels in every e-mail, every copied link and every screenshot.
     *
     * The second half is the one the contract spells out, and it is a scoping rule that looks wrong
     * until you say it out loud: **a conversation is scoped by `conversation_participants.user_id`,
     * never by `client_id`.** Two people who work for the same company are two people. A thread one of
     * them opened about a payment dispute, a complaint or a contract is theirs, and "same client" is
     * exactly the shortcut somebody writes when the client panel's other twelve screens all scope by
     * `client_id` and this one must not. The fixture therefore gives one client **two portal logins** —
     * the primary binding on `clients.user_id` and a second on `client_contacts.user_id` with
     * `portal_access` (phase-05 §6.9, the two branches of `ClientContext`) — because with two separate
     * clients the wrong rule and the right rule give the same answer and the test proves nothing.
     */
    #[Test]
    public function test_notifications_and_messages_are_personal(): void
    {
        [, $userA] = $this->studentWithLogin();
        [, $userB] = $this->studentWithLogin();

        $mine = $this->notificationFor($userA);
        $theirs = $this->notificationFor($userB);

        $this->actingAs($userA)
            ->get(route('student.notifications.go', $mine, absolute: false))
            ->assertRedirect();

        $this->actingAs($userA)
            ->get(route('student.notifications.go', $theirs, absolute: false))
            ->assertNotFound();

        // And the refusal must not have marked it read on the way out.
        $this->assertNull(
            DB::table('notifications')->where('id', $theirs)->value('read_at'),
            'A refused open still marked another user’s notification as read.',
        );

        // --- and the conversation half -------------------------------------------------------

        [$client, $primary] = $this->clientWithLogin('Gamma Textiles');
        $colleague = $this->secondPortalLoginFor($client);

        $conversation = $this->conversationBetween($primary, $this->createSuperAdmin());

        $this->actingAs($primary)
            ->get(route('client.messages.show', $conversation, absolute: false))
            ->assertOk();

        // Same company, same client_id, same panel — and not in the room.
        $this->actingAs($colleague)
            ->get(route('client.messages.show', $conversation, absolute: false))
            ->assertNotFound();

        $this->actingAs($colleague)
            ->get(route('client.messages.index', absolute: false))
            ->assertOk()
            ->assertDontSee(self::THREAD_PROBE);

        // A refusal must not have quietly enrolled them either.
        $this->assertSame(
            0,
            (int) DB::table('conversation_participants')
                ->where('conversation_id', $conversation->getKey())
                ->where('user_id', $colleague->getKey())
                ->count(),
            'A refused read added the reader to the thread’s participants.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The cells this phase cannot yet assert
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-04, second half — the global scope.
     */
    #[Test]
    public function test_the_collaborator_global_scope_is_registered_on_every_owned_model(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.3 ISO-04 requires the global scope `BelongsToAuthenticatedCollaborator` '
            .'to be asserted active on 11 collaborator-owned models and both payment tables '
            .'(phase-10-12 [D-IMP-9]). No such class exists anywhere under app/ — a grep for the name '
            .'matches only the phase-10-12 contract. Every collaborator controller filters explicitly '
            .'through ResolvesOwnCollaborator (which the tests above do cover), so the scope is the '
            .'defence-in-depth layer described by phase-10-12 IR-6, not the only defence. Un-skip this '
            .'test by writing the scope; do not weaken it to assert the explicit filters twice.'
        );
    }

    /** ISO-08 — branch scoping (D11). */
    #[Test]
    public function test_branch_scoping(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.3 ISO-08 needs a two-branch institute fixture: a user with '
            .'users.branch_id = 1 seeing only `branch_id = 1 OR branch_id IS NULL`, a branch-2 student, '
            .'charge, receipt, batch and timetable entry each 404, and the fee-structure generator '
            .'stamping branch_id from the student rather than the form. No fixture builder under tests/ '
            .'creates a second branch, and this slice may not add one to tests/Feature/Institute/Concerns '
            .'(those files belong to phases 14-17). Owner: whoever ships the branch fixture.'
        );
    }

    /** ISO-09 — pipeline visibility (Phase 5 [D-P5-8]). */
    #[Test]
    public function test_pipeline_visibility_scope(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.3 ISO-09 asserts that `leads.view` without `leads.view_any` shows '
            .'only leads where assigned_to or created_by is the user, and 404s their activities, '
            .'follow-ups and conversions by id. tests/Feature/Crm ships only CrmSmokeTest.php and no '
            .'lead fixture builder, so this test would have to hand-build the pipeline — which would '
            .'test a shape LeadService never produces. Owner: Phase 5’s own suite, cited here so the '
            .'matrix cell is not silently empty.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ISO-10 — the global search palette
    |--------------------------------------------------------------------------
    */

    /**
     * ISO-10 — one query string, seven actors, and a result set that never outruns the scope behind it.
     *
     * **A search box is the one screen that queries every table in the system at once**, which makes it
     * the cheapest place to lose an isolation rule and the hardest place to notice: eleven providers
     * each carry their own `where`, and a provider whose scope is missing returns *more* results, which
     * looks like a search that works well.
     *
     * Three properties, and each catches a different failure:
     *
     *   1. **Every narrower actor's hits are a strict subset of Super Admin's.** A set that grows as
     *      the role narrows is a scope applied backwards; a set that is equal is a scope not applied.
     *   2. **Every hit that carries a link opens with 200.** This is the one the contract calls out —
     *      it catches an index that outruns its scopes from the other side, where the palette offers a
     *      record the detail screen then refuses. A 403 on a search result is a disclosure with a
     *      polite error page on top: the row was named, and only the door was locked.
     *   3. **Every planted row a narrower actor did *not* get is a row that actor genuinely cannot
     *      open.** Without this, a search that returned nothing at all would satisfy (1) and (2).
     *
     * On the contract's wording. Section 11.3 asks for sets that are "strictly decreasing,
     * non-overlapping", which cannot both be true of the same seven sets — a strictly decreasing chain
     * of subsets overlaps by construction, and the listed actor order (Super Admin, Accountant,
     * Receptionist, …) is not even the decreasing one, because the Receptionist holds `collaborators`
     * and the Accountant does not. What is asserted here is the readable intent: **strictly decreasing
     * in size along an explicit chain, each set contained in the one above it, and empty for all four
     * portals** — with the disjointness that "non-overlapping" is reaching for showing up where two
     * staff roles hold disjoint modules.
     *
     * The four portal actors are 403 rather than narrowed, and that is not a weaker answer: the three
     * routes live behind `panel:admin` (`routes/admin.php`), so a teacher, a collaborator, a student
     * and a client never reach the palette at all. The assertion below proves the refusal is not
     * vacuous by checking the rows it would have matched are really there.
     *
     * The hit *set* is read from `admin.search.suggest` rather than scraped out of the HTML: it is the
     * same `GlobalSearchService::search()` call, behind the identical middleware stack, returning the
     * same `SearchResults` as JSON — so the comparison is exact instead of a substring search over a
     * rendered page. `admin.search.index` is driven too, because it is the route the contract names and
     * because a 200 from it is what says the screen exists for that actor.
     */
    #[Test]
    public function test_global_search_respects_every_scope(): void
    {
        $token = self::SEARCH_TOKEN;

        [$client, $clientUser] = $this->clientWithLogin($token.' Holdings');
        [$student, $studentUser] = $this->studentWithLogin($token.' Student');
        [$partner, $partnerUser] = $this->partnerWithLogin($token.' Partner');
        [, $teacherUser] = $this->teacherWithLogin();

        // What is on the table, and the admin screen each row is reached through. Anything an actor
        // does not get back must be unreachable for them — property (3).
        $planted = [
            'client:'.$client->getKey() => ['admin.clients.show', $client->getKey()],
            'student:'.$student->getKey() => ['admin.students.show', $student->getKey()],
            'collaborator:'.$partner->getKey() => ['admin.collaborators.show', $partner->getKey()],
        ];

        // The chain, widest first. Named roles because the contract names them; the *expectation* is
        // derived from what each actor gets back, never spelled out, so a RoleSeeder change moves the
        // numbers rather than breaking the test's meaning.
        $chain = [
            'Super Admin' => $this->createSuperAdmin(),
            'Receptionist' => $this->createUserWithRole('Receptionist'),
            'Accountant' => $this->createUserWithRole('Accountant'),
        ];

        $sets = [];

        foreach ($chain as $label => $actor) {
            $this->actingAs($actor)
                ->get(route('admin.search.index', ['q' => $token], absolute: false))
                ->assertOk();

            $sets[$label] = $this->searchHits($actor, $token);
        }

        // (1) Super Admin sees all three, or the fixture — not the scope — is what this test measures.
        $everything = array_keys($planted);
        sort($everything);

        $this->assertSame(
            $everything,
            $sets['Super Admin'],
            'The fixture did not reach the palette at all, so nothing below this line means anything.',
        );

        $previous = null;

        foreach ($sets as $label => $set) {
            if ($previous !== null) {
                [$wider, $widerSet] = $previous;

                $this->assertSame(
                    [],
                    array_values(array_diff($set, $widerSet)),
                    sprintf('%s sees a record %s does not — the chain is not a chain.', $label, $wider),
                );

                $this->assertLessThan(
                    count($widerSet),
                    count($set),
                    sprintf(
                        '%s and %s returned the same number of hits. Either a scope is missing or this '
                        .'fixture no longer distinguishes the two roles.',
                        $label,
                        $wider,
                    ),
                );
            }

            $previous = [$label, $set];
        }

        // (2) and (3), per actor.
        foreach ($chain as $label => $actor) {
            $this->assertHitsOpen($actor, $token, $label);

            foreach (array_diff(array_keys($planted), $sets[$label]) as $withheld) {
                [$route, $id] = $planted[$withheld];

                $status = $this->actingAs($actor)->get(route($route, $id, absolute: false))->getStatusCode();

                $this->assertContains(
                    $status,
                    [403, 404],
                    sprintf(
                        '%s was not shown `%s` by the palette but can open it anyway (%d) — the search '
                        .'scope and the screen disagree, and one of the two is wrong.',
                        $label,
                        $withheld,
                        $status,
                    ),
                );
            }
        }

        // The four portals: refused at the door, and the refusal is not vacuous.
        foreach ([
            'Teacher' => $teacherUser,
            'Collaborator A' => $partnerUser,
            'Student A' => $studentUser,
            'Client A' => $clientUser,
        ] as $label => $actor) {
            foreach (['admin.search.index', 'admin.search.suggest'] as $name) {
                $this->actingAs($actor)
                    ->get(route($name, ['q' => $token], absolute: false))
                    ->assertForbidden();
            }

            $this->assertNotEmpty(
                $sets['Super Admin'],
                sprintf('%s was refused a palette that had nothing in it to refuse.', $label),
            );
        }

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * A portal-enabled client bound to a user holding the seeded `Client` role.
     *
     * @return array{0: Client, 1: User}
     */
    private function clientWithLogin(string $name): array
    {
        $user = $this->createUserWithRole('Client');

        $client = new Client;
        $client->forceFill([
            'client_code' => 'CL-ISO'.str_pad((string) $user->getKey(), 5, '0', STR_PAD_LEFT),
            'name' => $name,
            'company_name' => $name,
            'email' => sprintf('iso-%d@example.test', $user->getKey()),
            'status' => ClientStatus::Active->value,
            'portal_enabled' => true,
            'user_id' => $user->getKey(),
        ])->save();

        return [$client->refresh(), $user];
    }

    /**
     * An issued (never draft) invoice for this client.
     */
    private function issuedInvoice(Client $client)
    {
        $invoice = $this->draftInvoice($client, [$this->line('25000.00')]);

        return app(InvoiceService::class)->issue($invoice, $this->createSuperAdmin());
    }

    /**
     * A second portal login on the **same** client — `client_contacts.user_id` with `portal_access`,
     * which is `ClientContext`'s second resolution branch (phase-05 §6.9).
     *
     * Written with the query builder for the same reason the student and collaborator bindings are:
     * `client_contacts.user_id` is not mass assignable, and a fixture that could set it through the
     * model would be proving the guard is missing.
     */
    private function secondPortalLoginFor(Client $client): User
    {
        $user = $this->createUserWithRole('Client');

        DB::table('client_contacts')->insert([
            'client_id' => $client->getKey(),
            'user_id' => $user->getKey(),
            'name' => 'Second Contact '.$user->getKey(),
            'email' => sprintf('contact-%d@example.test', $user->getKey()),
            'is_primary' => false,
            'portal_access' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * A direct thread, opened through the service so `direct_key`, both participant rows and the
     * opening message are the shapes `ConversationService` produces — a hand-built conversation would
     * be scoped correctly by accident.
     */
    private function conversationBetween(User $initiator, User $target): Conversation
    {
        return app(ConversationService::class)->startDirect($initiator, $target, [
            'body' => self::THREAD_PROBE,
        ]);
    }

    /**
     * @return array{0: Student, 1: User}
     */
    private function studentWithLogin(?string $name = null): array
    {
        $student = $name === null ? $this->fixtureStudent() : $this->namedStudent($name);
        $user = $this->createUserWithRole('Student');

        // `students.user_id` is not mass assignable (D2: the record exists before the login does), so
        // the binding is written the way CollaboratorPanelTest writes its own.
        DB::table('students')->where('id', $student->getKey())->update(['user_id' => $user->getKey()]);

        return [$student->refresh(), $user];
    }

    /**
     * @return array{0: Teacher, 1: User}
     */
    private function teacherWithLogin(): array
    {
        $teacher = $this->teacher();
        $user = $this->createUserWithRole('Teacher');

        DB::table('teachers')->where('id', $teacher->getKey())->update(['user_id' => $user->getKey()]);

        return [$teacher->refresh(), $user];
    }

    /**
     * A student carrying a chosen name, through `StudentService` like every other student in this file.
     *
     * ISO-10 needs one search token to appear on three different kinds of record, and the sequence
     * name `fixtureStudent()` assigns cannot carry it.
     */
    private function namedStudent(string $name): Student
    {
        return app(StudentService::class)->create([
            'name' => $name,
            'phone' => '0377'.str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * A partner with one posted commission and a login on the collaborator panel.
     *
     * @return array{0: Collaborator, 1: User, 2: CollaboratorCommissionLedgerEntry}
     */
    private function partnerWithLogin(?string $name = null): array
    {
        $partner = $this->partner('10.0000');

        if ($name !== null) {
            // The name is display text, not a financial column, so it is set on the model. Nothing in
            // the commission engine reads it.
            $partner->forceFill(['name' => $name])->save();
        }

        $this->receive($this->charge($partner, '200000.00'), '40000.00', ['on' => '2026-02-10']);

        $user = $this->createUserWithRole('Collaborator');

        DB::table('collaborators')->where('id', $partner->getKey())->update(['user_id' => $user->getKey()]);

        $entry = CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $partner->getKey())
            ->firstOrFail();

        return [$partner->refresh(), $user, $entry];
    }

    /**
     * One database notification addressed to this user.
     */
    private function notificationFor(User $user): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\IsolationProbe',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->getKey(),
            'data' => json_encode(['title' => 'Isolation probe'], JSON_THROW_ON_ERROR),
            'event_key' => 'isolation.probe',
            'level' => 'info',
            'url' => '/student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Assertions
    |--------------------------------------------------------------------------
    */

    /**
     * What the palette returned for this viewer, as a sorted list of `type:id`.
     *
     * Read from `admin.search.suggest` — the same `GlobalSearchService::search()` call behind the same
     * middleware as `admin.search.index`, but returning `SearchResults::toArray()` as JSON. Comparing
     * decoded ids is exact; comparing rendered HTML is a substring search, and a substring search on a
     * results page cannot tell "this row was returned" from "this word appears in the empty-state
     * copy".
     *
     * @return list<string>
     */
    private function searchHits(User $viewer, string $term): array
    {
        $payload = $this->actingAs($viewer)
            ->getJson(route('admin.search.suggest', ['q' => $term], absolute: false))
            ->assertOk()
            ->json();

        $hits = [];

        foreach ((array) ($payload['groups'] ?? []) as $group) {
            foreach ((array) ($group['hits'] ?? []) as $hit) {
                $hits[] = $hit['type'].':'.$hit['id'];
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * Every linked hit this viewer got back opens with 200.
     *
     * A hit whose `url` is null is **not** a failure: §6.23 renders a record the viewer matched but may
     * not open without a link, deliberately, so the count never lies about what matched. What must
     * never happen is a hit that offers a link the viewer is then refused — that is a row named to
     * somebody who could not have reached it, with a polite error page standing in for the scope.
     */
    private function assertHitsOpen(User $viewer, string $term, string $label): void
    {
        $payload = $this->actingAs($viewer)
            ->getJson(route('admin.search.suggest', ['q' => $term], absolute: false))
            ->assertOk()
            ->json();

        foreach ((array) ($payload['groups'] ?? []) as $group) {
            foreach ((array) ($group['hits'] ?? []) as $hit) {
                $url = $hit['url'] ?? null;

                if (! is_string($url) || $url === '') {
                    continue;
                }

                // Path only: `urlFor()` builds an absolute URL, and the test client should exercise the
                // route rather than the host the app happens to be configured with.
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);

                $status = $this->actingAs($viewer)->get($path)->getStatusCode();

                $this->assertSame(
                    200,
                    $status,
                    $status >= 500
                        ? sprintf(
                            'The palette offered %s the link %s and the screen threw a %d. That is a '
                            .'thin fixture or a broken detail page, not an isolation failure — read it '
                            .'as "this row cannot be rendered", not as "this row leaked".',
                            $label,
                            $path,
                            $status,
                        )
                        : sprintf(
                            'The palette offered %s the link %s and the screen refused it (%d). **A '
                            .'search result that 403s has already disclosed the row it is protecting** '
                            .'— the name, the code and the badge were all on the page before the click.',
                            $label,
                            $path,
                            $status,
                        ),
                );
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertBodyOmits(string $body, array $columns, string $screen): void
    {
        foreach ($columns as $column) {
            $this->assertStringNotContainsString(
                $column,
                $body,
                sprintf('`%s` reached the response body of %s — a whole model was serialised.', $column, $screen),
            );
        }
    }
}
