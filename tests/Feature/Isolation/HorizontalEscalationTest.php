<?php

declare(strict_types=1);

namespace Tests\Feature\Isolation;

use App\DataObjects\Collaborator\PayoutRequestData;
use App\Enums\ClientStatus;
use App\Enums\PayoutMethod;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorPayoutAccount;
use App\Models\Crm\Client;
use App\Models\Institute\Student;
use App\Models\Institute\Teacher;
use App\Models\Module;
use App\Models\User;
use App\Services\Collaborator\PayoutService;
use App\Services\Finance\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * ESC-01 … ESC-06 — horizontal privilege escalation: the same tenant class, two tenants
 * (phase-24-25 section 11.4).
 *
 * Section 11.3 asks "can A *read* B's row?". This file asks the harder question: **can A *act* as B?**
 * The two are not the same bug. A panel can scope every list perfectly and still accept a foreign id
 * in a POST body, because the read path and the write path are written months apart and only the read
 * path has a screen somebody looks at. The write path fails silently and successfully.
 *
 * **404 and 403 are both correct answers and they are not interchangeable.** The convention this
 * system commits to, and that this file pins:
 *
 *   - **404** when the *row* is not yours. A 403 would confirm it exists, which is the one fact an
 *     attacker walking ids is trying to establish. Every portal controller resolves its tenant from
 *     the session and answers 404 on a foreign id.
 *   - **403** when the *ability* is absent. Nothing is disclosed by saying "you may not request
 *     payouts": that is a fact about the actor, not about anyone else's data.
 *   - **422** when the foreign id arrives as a *field* rather than as a route parameter, because the
 *     rule that catches it is `Rule::exists()->where(owner)` (SEC-33) and a validation failure is what
 *     a rule produces.
 *
 * Every refusal in this file is followed by proof that **nothing was written**, and every money
 * refusal additionally re-runs `assertWalletMatchesLedger()` for *both* partners. A refusal that still
 * moved a paisa is not a refusal.
 *
 * The small tenant fixtures below are deliberately local to this file rather than shared with
 * `TenantScopeTest`: this slice creates only the files section 11.3/11.4 name, and a shared concern
 * would be a fifth file in a directory two other agents also write to.
 */
#[Group('isolation')]
#[Group('idor')]
final class HorizontalEscalationTest extends TestCase
{
    // See TenantScopeTest for why `setting()` needs resolving: BuildsFinancialFixtures and (through
    // BuildsAdmissions) BuildsCatalogue both declare it.
    use BuildsFinancialFixtures, BuildsInvoiceFixtures, BuildsSchedules {
        BuildsFinancialFixtures::setting insteadof BuildsSchedules;
        BuildsSchedules::setting as catalogueSetting;
    }
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Tables whose row count must not move while an escalation attempt is being refused.
     *
     * @var list<string>
     */
    private const MONEY_TABLES = [
        'collaborator_commission_ledger_entries',
        'collaborator_commission_entitlements',
        'collaborator_payouts',
        'collaborator_payout_allocations',
        'student_fee_payments',
        'payment_reversals',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'automatic');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('collaborator.minimum_payout', '0.00');
        $this->setting('collaborator.payout_request_enabled', true);
        $this->setting('finance.backdate_limit_days', 3650);
    }

    /*
    |--------------------------------------------------------------------------
    | ESC-01 — client A cannot act as client B
    |--------------------------------------------------------------------------
    */

    /**
     * ESC-01 — A reads B's invoice, marks B's notification read, and posts B's client id as a hidden
     * field. None of it lands.
     *
     * The hidden-field case is the one worth stating out loud: the client a request belongs to comes
     * from `ClientContext` (resolved from the session, re-resolved on every request), never from the
     * body. A `client_id` in a payload is a number the browser can change; a session is not.
     */
    #[Test]
    public function test_client_a_cannot_act_as_client_b(): void
    {
        [$clientA, $userA] = $this->clientWithLogin('Alpha Holdings');
        [$clientB] = $this->clientWithLogin('Beta Traders');

        $invoiceB = $this->issuedInvoice($clientB);
        $notificationB = $this->notificationFor($this->clientLoginOf($clientB));

        $this->actingAs($userA)
            ->get(route('client.invoices.show', $invoiceB, absolute: false))
            ->assertNotFound();

        $this->actingAs($userA)
            ->post(route('client.notifications.read', $notificationB, absolute: false))
            ->assertNotFound();

        $this->assertNull(
            DB::table('notifications')->where('id', $notificationB)->value('read_at'),
            'A refused request still marked another tenant’s notification read.',
        );

        // A forged `client_id` on a legitimate write: the ticket, if it is created at all, belongs to
        // the session's client and never to B. `TicketService::create()` derives `client_id` from
        // `ClientContext`, so the forged key is not merely rejected — it is never read.
        //
        // A missing department makes this a 302-with-errors rather than a create, which is still a
        // valid outcome for the assertion below: what must never happen is a row bound to B.
        $this->actingAs($userA)->post(route('client.tickets.store', absolute: false), [
            'subject' => 'Escalation probe',
            'description' => 'Posting another tenant’s client id in the body.',
            'ticket_department_id' => DB::table('ticket_departments')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->value('id'),
            'client_id' => $clientB->getKey(),
        ]);

        $this->assertSame(
            0,
            (int) DB::table('support_tickets')->where('client_id', $clientB->getKey())->count(),
            'A forged `client_id` in a POST body created a row against another tenant.',
        );

        // Belt and braces: whatever was created, it is A's.
        $foreign = DB::table('support_tickets')
            ->whereNotNull('client_id')
            ->whereNot('client_id', $clientA->getKey())
            ->count();

        $this->assertSame(0, (int) $foreign, 'A ticket was bound to a client the actor is not.');
    }

    /*
    |--------------------------------------------------------------------------
    | ESC-02 — student A cannot act as student B
    |--------------------------------------------------------------------------
    */

    /**
     * ESC-02 — A opens B's receipt, B's fee slip and B's notification: 404 every time, nothing written.
     *
     * A receipt is the highest-value target on this panel: it names an amount, a date and a person,
     * and its id is sequential.
     */
    #[Test]
    public function test_student_a_cannot_act_as_student_b(): void
    {
        [$studentA, $userA] = $this->studentWithLogin();
        [$studentB, $userB] = $this->studentWithLogin();

        $chargeB = $this->charge(null, '30000.00', ['student_id' => $studentB->getKey()]);
        $paymentB = $this->receive($chargeB, '10000.00', ['on' => '2026-02-10'])->payment;

        $chargeA = $this->charge(null, '30000.00', ['student_id' => $studentA->getKey()]);

        $before = $this->rowCounts(self::MONEY_TABLES);

        // Their own rows still work — otherwise "404 for everyone" would pass this test.
        $this->actingAs($userA)
            ->get(route('student.fees.show', $chargeA, absolute: false))
            ->assertOk();

        foreach (['student.fees.show', 'student.fees.slip'] as $name) {
            $this->actingAs($userA)
                ->get(route($name, $chargeB, absolute: false))
                ->assertNotFound();
        }

        $this->actingAs($userA)
            ->get(route('student.payments.receipt', $paymentB, absolute: false))
            ->assertNotFound();

        $this->actingAs($userA)
            ->post(route('student.notifications.read', $this->notificationFor($userB), absolute: false))
            ->assertNotFound();

        $this->assertNothingWritten($before, 'a student read another student’s money rows');
    }

    /*
    |--------------------------------------------------------------------------
    | ESC-03 — collaborator A cannot reach B's money
    |--------------------------------------------------------------------------
    */

    /**
     * ESC-03 — the one that matters, because every refusal here is a refusal to move money.
     *
     * Four distinct attempts, three distinct correct statuses:
     *
     *   1. request a payout **without** `collaborator_portal.payout_request` -> **403**, even though
     *      `collaborator.payout_request_enabled` is on. The setting opens the feature; the permission
     *      decides who holds it, and the setting must never be able to stand in for the permission
     *      (spine FT-48).
     *   2. request a payout naming **B's** `payout_account_id` -> **422**, from
     *      `Rule::exists()->where('collaborator_id', own)` in `RequestPayoutRequest` (SEC-33).
     *   3. cancel **B's** payout by id -> **404**, because the row is not theirs.
     *   4. pass `collaborator_id = B` in the body -> ignored; the context comes from the session.
     *
     * And there is no approve route on this panel at all, which is asserted structurally rather than
     * by probing a URL: a route that does not exist cannot be mis-guarded later.
     */
    #[Test]
    public function test_collaborator_a_cannot_reach_b_money(): void
    {
        [$partnerA, $userA] = $this->partnerWithLogin();
        [$partnerB, $userB] = $this->partnerWithLogin();

        $accountA = $this->payoutAccount($partnerA);
        $accountB = $this->payoutAccount($partnerB);

        $payoutB = $this->payoutFor($partnerB, $accountB, $userB);
        $payoutBStatus = $payoutB->status;

        $before = $this->rowCounts(self::MONEY_TABLES);

        // (1) The ability is absent on the seeded Collaborator role (RoleSeeder excludes
        // `payout_request` deliberately), so this is 403 — a fact about the actor, not about B.
        $this->actingAs($userA)
            ->post(route('collaborator.payouts.store', absolute: false), [
                'amount' => '1000.00',
                'method' => PayoutMethod::BankTransfer->value,
                'payout_account_id' => $accountA->getKey(),
            ])
            ->assertForbidden();

        $this->grantPermissions($userA, 'collaborator_portal.payout_request');
        $userA = $userA->fresh();

        // (2) Now they hold the ability. B's account id is a *field*, so the refusal is 422.
        $this->assertRejected(
            $this->actingAs($userA)->post(route('collaborator.payouts.store', absolute: false), [
                'amount' => '1000.00',
                'method' => PayoutMethod::BankTransfer->value,
                'payout_account_id' => $accountB->getKey(),
                // (4) …and a forged owner in the same breath.
                'collaborator_id' => $partnerB->getKey(),
            ]),
            'payout_account_id',
        );

        $this->assertSame(
            1,
            (int) CollaboratorPayout::query()->where('collaborator_id', $partnerB->getKey())->count(),
            'A payout was created against another partner.',
        );

        // (3) B's payout, by id, as A.
        $this->actingAs($userA)
            ->post(route('collaborator.payouts.cancel', $payoutB, absolute: false))
            ->assertNotFound();

        $this->actingAs($userA)
            ->get(route('collaborator.payouts.show', $payoutB, absolute: false))
            ->assertNotFound();

        // Captured before the attempt: `refresh()` mutates the model in place, so comparing it with
        // itself would assert nothing.
        $this->assertSame(
            $payoutBStatus,
            $payoutB->refresh()->status,
            'A refused cancel still moved another partner’s payout.',
        );

        $this->assertNothingWritten($before, 'a partner tried to reach another partner’s money');

        $this->assertWalletMatchesLedger($partnerA);
        $this->assertWalletMatchesLedger($partnerB);
    }

    /**
     * There is **no approve, adjust or ledger-write route on the collaborator panel**, and the guard
     * is that the route does not exist.
     *
     * Probing a URL would only prove that today's wiring refuses it. Asserting the route table proves
     * the decision: a partner never becomes the person who approves their own money, so the endpoint
     * is never registered in the first place and can never be mis-guarded by a later edit.
     */
    #[Test]
    public function test_the_collaborator_panel_registers_no_approval_or_ledger_write_route(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'collaborator.')) {
                continue;
            }

            if (preg_match('/\.(approve|reject|adjust|ledger|reverse|mark-paid|markPaid)\b/i', $name) === 1) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The collaborator panel now registers a route that decides money: '.implode(', ', $offenders),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ESC-04 — teacher A cannot reach B's batch
    |--------------------------------------------------------------------------
    */

    /**
     * ESC-04 — A reads B's batch, and posts B's `batch_id` into an assignment.
     *
     * `StoreAssignmentRequest` validates `batch_id` with a plain `Rule::exists('batches','id')` — no
     * owner clause — so validation **passes** and the only thing standing between A and B's batch is
     * `TeacherController::store()` checking the id against `TeacherScope::batchIds()`. That is exactly
     * the shape of guard that disappears in a refactor, which is why the assertion below checks the
     * stored rows and not just the status code.
     */
    #[Test]
    public function test_teacher_a_cannot_reach_b_batch(): void
    {
        [$teacherA, $userA] = $this->teacherWithLogin();
        [$teacherB] = $this->teacherWithLogin();

        $batchA = $this->batch(null, ['teacher_id' => $teacherA->getKey(), 'code' => 'BATCH-ESC-ALPHA']);
        $batchB = $this->batch(null, ['teacher_id' => $teacherB->getKey(), 'code' => 'BATCH-ESC-BETA']);

        $assignmentsBefore = (int) DB::table('assignments')->count();

        $this->actingAs($userA)
            ->get(route('teacher.batches.show', $batchA, absolute: false))
            ->assertOk();

        foreach (['teacher.batches.show', 'teacher.progress.show', 'teacher.certificates.candidates'] as $name) {
            $this->actingAs($userA)
                ->get(route($name, $batchB, absolute: false))
                ->assertNotFound();
        }

        $this->actingAs($userA)->post(route('teacher.assignments.store', absolute: false), [
            'batch_id' => $batchB->getKey(),
            'title' => 'Escalation probe',
            'total_marks' => '100.00',
            'deadline_at' => now()->addWeek()->toDateTimeString(),
        ])->assertNotFound();

        $this->assertSame(
            $assignmentsBefore,
            (int) DB::table('assignments')->count(),
            'An assignment was created against another teacher’s batch.',
        );

        $this->assertSame(
            0,
            (int) DB::table('assignments')->where('batch_id', $batchB->getKey())->count(),
            'A row now points at a batch its author does not teach.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ESC-05 — payroll
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function test_employee_cannot_read_another_employee_payroll(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.4 ESC-05 needs two employees with salary structures, slips, advances '
            .'and documents, and the assertion that the difference between a Developer and an HR user is '
            .'a permission rather than a role string. tests/Feature/Hr ships only HrPayrollTest.php and no '
            .'fixture builder this file may reuse, and this slice may not add one to tests/Feature/Hr '
            .'(that directory belongs to Phase 7). Writing it against hand-inserted payroll rows would '
            .'test a shape PayrollService never produces. Owner: Phase 7’s suite, or a follow-up slice '
            .'that ships tests/Feature/Hr/Concerns/BuildsPayroll.php first.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ESC-06 — and vertical escalation, while we are here
    |--------------------------------------------------------------------------
    */

    /**
     * ESC-06 — a non-Super-Admin cannot grant themselves anything.
     *
     * Every target is a **real, existing model**, on purpose. `SubstituteBindings` runs before the
     * `can:` middleware, so a made-up id would answer 404 before authorisation was ever consulted and
     * the test would pass without proving anything about permissions.
     *
     * The Accountant is the actor because it is the most privileged non-admin role in the system: it
     * reads money everywhere. If separation of duties holds for the Accountant it holds for the eleven
     * roles below it.
     */
    #[Test]
    public function test_vertical_escalation_is_impossible_too(): void
    {
        $actor = $this->createUserWithRole('Accountant');
        $role = $actor->roles->first();

        $module = Module::query()->where('is_core', false)->firstOrFail();
        $wasEnabled = (bool) $module->is_enabled;

        // Toggle a module.
        $this->actingAs($actor)
            ->post(route('admin.modules.toggle', $module, absolute: false))
            ->assertForbidden();

        $this->assertSame(
            $wasEnabled,
            (bool) $module->fresh()->is_enabled,
            'A refused toggle still flipped the module.',
        );

        // Edit the role they hold — the shortest path to every permission in the system.
        $this->actingAs($actor)
            ->put(route('admin.roles.update', $role, absolute: false), [
                'label' => 'Accountant',
                'permissions' => ['users.view_any'],
            ])
            ->assertForbidden();

        // Change their own account status.
        $this->actingAs($actor)
            ->patch(route('admin.users.status', $actor, absolute: false), ['status' => 'active'])
            ->assertForbidden();

        // And they still hold exactly the one role they started with.
        $this->assertSame(
            ['Accountant'],
            $actor->fresh()->roles->pluck('name')->all(),
            'The actor ended the test holding a different set of roles.',
        );

        // The backup register is Super-Admin-only separation of duties (phase-24-25 section 9): even
        // Admin is 403 there, because whoever can take a copy of the database can read every salary,
        // every commission and every password hash in it.
        //
        // **The permission is asserted whether or not the route exists yet.** `admin.backups.*` lands
        // with Phase 25; a bare `Route::has() && …` would pass today by asserting nothing at all, and
        // would keep passing on the day somebody registered the route without a guard, because the
        // whole expression short-circuits on the first run and nobody re-reads a green test. The grant
        // is the invariant — the route is only where it is spent.
        $this->assertFalse(
            $actor->can('backups.view_any'),
            'The Accountant holds `backups.view_any`. Phase 1 §5 excludes `backups.*` from Admin and '
            .'below; that exclusion is separation of duties, not an oversight.',
        );

        $this->assertFalse(
            $actor->can('backups.restore'),
            'The Accountant can restore a backup — a restore overwrites live financial history with a '
            .'copy, which is a delete with extra steps (phase-24-25 section 9).',
        );

        if (Route::has('admin.backups.index')) {
            $this->actingAs($actor)
                ->get(route('admin.backups.index', absolute: false))
                ->assertForbidden();
        }

        // The SMTP credentials are the other Super-Admin-only grant, for the same shape of reason:
        // whoever holds them receives every password-reset mail in the system (phase-02 §3, §6).
        $this->assertFalse(
            $actor->can('settings.edit_mail'),
            'The Accountant can edit the mail settings, and so can redirect every password reset.',
        );
    }

    /**
     * ESC-06's audit half — **not asserted, because the application does not do it yet.**
     *
     * Section 11.4 ends ESC-06 with "each leaves an activity row for the attempt where the route is
     * financial or administrative". Nothing in this system records an authorisation *failure*: there
     * is no listener on `Illuminate\Auth\Access\Events\*`, no `AuthorizationException` handler that
     * writes to `activity_log`, and `app/Listeners` holds only the three login recorders. Every 403
     * above therefore leaves no trace at all.
     *
     * That is worth having as a named gap rather than a silent one. **An escalation attempt that is
     * refused and not recorded is an attacker's free probe**: they learn the boundary, and the only
     * evidence is a 403 in a web-server log nobody reads next to ten thousand others. The refusals are
     * the cheapest intrusion signal this system could have.
     *
     * Closing it means a listener plus its registration, and registration is in
     * `AppServiceProvider`/`bootstrap` — files this slice may not touch. The snippet is in the slice
     * report. Un-skip by writing the listener; do not weaken this into "some activity row exists",
     * which every request already produces.
     */
    #[Test]
    public function test_vertical_escalation_is_impossible_too_and_is_recorded(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.4 ESC-06 requires a refused financial or administrative attempt to '
            .'leave an activity row. No listener records an authorisation denial anywhere in this '
            .'application: app/Listeners holds RecordFailedLogin, RecordLogout and RecordSuccessfulLogin '
            .'and nothing else, and no handler writes activity_log on AuthorizationException. Asserting '
            .'it today would fail on every one of the six attempts in the test above; asserting "an '
            .'activity row exists" instead would pass on rows an ordinary request writes anyway. Owner: '
            .'whoever ships the denial listener and its registration (AppServiceProvider is outside this '
            .'slice).'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: Client, 1: User}
     */
    private function clientWithLogin(string $name): array
    {
        $user = $this->createUserWithRole('Client');

        $client = new Client;
        $client->forceFill([
            'client_code' => 'CL-ESC'.str_pad((string) $user->getKey(), 5, '0', STR_PAD_LEFT),
            'name' => $name,
            'company_name' => $name,
            'email' => sprintf('esc-%d@example.test', $user->getKey()),
            'status' => ClientStatus::Active->value,
            'portal_enabled' => true,
            'user_id' => $user->getKey(),
        ])->save();

        return [$client->refresh(), $user];
    }

    private function clientLoginOf(Client $client): User
    {
        return User::query()->findOrFail((int) $client->user_id);
    }

    private function issuedInvoice(Client $client)
    {
        $invoice = $this->draftInvoice($client, [$this->line('25000.00')]);

        return app(InvoiceService::class)->issue($invoice, $this->createSuperAdmin());
    }

    /**
     * @return array{0: Student, 1: User}
     */
    private function studentWithLogin(): array
    {
        $student = $this->fixtureStudent();
        $user = $this->createUserWithRole('Student');

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
     * A partner with money in the wallet and a login on the collaborator panel.
     *
     * @return array{0: Collaborator, 1: User}
     */
    private function partnerWithLogin(): array
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '200000.00'), '100000.00', ['on' => '2026-02-10']);

        $user = $this->createUserWithRole('Collaborator');

        DB::table('collaborators')->where('id', $partner->getKey())->update(['user_id' => $user->getKey()]);

        return [$partner->refresh(), $user];
    }

    private function payoutAccount(Collaborator $collaborator): CollaboratorPayoutAccount
    {
        $row = new CollaboratorPayoutAccount;

        $row->forceFill([
            'collaborator_id' => $collaborator->getKey(),
            'label' => 'Bank',
            'method' => PayoutMethod::BankTransfer->value,
            'account_title' => (string) $collaborator->name,
            'bank_name' => 'Habib Bank',
            'details_encrypted' => ['account_number' => '0000001123456702'],
            'account_last4' => '6702',
            'is_default' => true,
            'is_verified' => true,
            'status' => 'active',
        ])->save();

        return $row->refresh();
    }

    /**
     * A real payout for this partner, requested through the service so it is the shape the application
     * produces (and so its allocations exist and its wallet still reconciles).
     */
    private function payoutFor(Collaborator $collaborator, CollaboratorPayoutAccount $account, User $actor): CollaboratorPayout
    {
        return app(PayoutService::class)->request(
            $collaborator,
            new PayoutRequestData(
                requestedAmount: '1000.00',
                method: PayoutMethod::BankTransfer,
                payoutAccountId: (int) $account->getKey(),
            ),
            $actor,
        );
    }

    private function notificationFor(User $user): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\EscalationProbe',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->getKey(),
            'data' => json_encode(['title' => 'Escalation probe'], JSON_THROW_ON_ERROR),
            'event_key' => 'isolation.probe',
            'level' => 'info',
            'url' => '/',
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
     * A foreign id arriving as a **field** is refused — 422 on a JSON-shaped response, or the 302 with
     * a validation error that a web form gets.
     *
     * Both are the same refusal. Laravel renders `ValidationException` as 422 only when the request
     * expects JSON; a panel form gets a redirect back with the error bag. Insisting on one of the two
     * would make this assertion a statement about content negotiation rather than about authorisation,
     * so it accepts either and then names the field, which is the part that must not drift.
     */
    private function assertRejected(TestResponse $response, string $field): void
    {
        $status = $response->getStatusCode();

        if (in_array($status, [403, 404], true)) {
            // Also acceptable per section 11.4 ("403/404/422"): the route refused before validation.
            return;
        }

        $this->assertContains(
            $status,
            [302, 422],
            sprintf('A foreign `%s` was answered with %d — it was not refused at all.', $field, $status),
        );

        $response->assertSessionHasErrors($field);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $before
     */
    private function assertNothingWritten(array $before, string $context): void
    {
        foreach ($before as $table => $expected) {
            $this->assertSame(
                $expected,
                (int) DB::table($table)->count(),
                sprintf('%s, and yet `%s` changed.', $context, $table),
            );
        }
    }
}
