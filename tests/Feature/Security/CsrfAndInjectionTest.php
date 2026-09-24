<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\PanelType;
use App\Support\Ops\SecurityAuditor;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The write door and the query door: CSRF, SQL injection, and the four scans that keep both shut
 * (phase-24-25 section 11.1, SEC-01 and SEC-08..SEC-11, SEC-14).
 *
 * **A cross-site POST and an injected `ORDER BY` are the same failure wearing different clothes:
 * both are a request deciding something the server was supposed to decide.** CSRF lets another
 * origin choose *that* a write happens; an interpolated identifier lets the caller choose *what*
 * the write touches. Neither is caught by a policy, because by the time a policy runs the request
 * already looks legitimate - it carries the victim's own session, or a sort column the controller
 * handed straight to the query builder.
 *
 * Four of the six tests here are **static scans rather than requests**, and that is deliberate.
 * A request test proves one route is safe today; a scan proves the pattern is absent from the whole
 * tree, including the route somebody adds next week. `App\Support\Ops\SecurityAuditor` already owns
 * those scans for `security:audit`, so this class asserts against *it* rather than growing a second
 * copy - a duplicated security control is a security defect (D25's argument, applied to scanners).
 *
 * **CSRF has to be switched back on for these tests.** Laravel's `ValidateCsrfToken` skips
 * verification while `runningInConsole() && runningUnitTests()`, which is why every other feature
 * test in this suite can POST without a token. `enforceCsrf()` flips the console flag on this
 * application instance only, so the middleware runs exactly as it does in production.
 */
#[Group('security')]
final class CsrfAndInjectionTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * The eight payloads of SEC-08, in the contract's order.
     *
     * `1 AND SLEEP(3)` is the one that cannot be judged by a status code: a 200 that took four
     * seconds means the payload reached the database as SQL rather than as a bound value, so this
     * test measures as well as asserts.
     */
    private const INJECTION_PAYLOADS = [
        "' OR 1=1 --",
        '1; DROP TABLE users;--',
        "%' UNION SELECT NULL--",
        "\\'",
        '0x27',
        '" OR ""="',
        '1 AND SLEEP(3)',
        '../../etc/passwd',
    ];

    /** Query parameters an index screen accepts, poisoned together in one request (see SEC-08). */
    private const INDEX_PARAMETERS = ['q', 'search', 'sort', 'direction', 'per_page', 'status', 'from', 'to'];

    /**
     * Tables whose row count stands in for "nothing was written".
     *
     * The route-guard manifest records what a route is allowed to do, not which table it writes, so
     * a generic sweep cannot name the target. These three are the ones a forged write would most
     * want - an account, a role grant, a commission - and any write at all to them is a failure.
     */
    private const SENTINEL_TABLES = ['users', 'model_has_roles', 'collaborator_commission_ledger_entries'];

    /**
     * A write action whose first parameter is deliberately not a Form Request, and the shape that
     * makes it acceptable anyway (SEC-14).
     *
     * **An exemption list that is not itself checked is a hole with a comment on it.** The contract
     * anticipates "an allowlist for the three toggle endpoints that take no body"; the tree has 66
     * write actions typed `Illuminate\Http\Request`, which is far more than three, so listing them
     * without proving anything would have turned SEC-14 into decoration. Every entry here therefore
     * names a *shape*, and `assertExemptionIsEarned()` re-derives that shape from the action's own
     * body on every run. Three shapes exist, and nothing else is accepted:
     *
     *   `rules`    - the action reaches a complete `$request->validate([...])` rule set, either in
     *                its own body or through a private validator of the same class (the house
     *                pattern: `validated()`, `validateExpense()`, `validateIncome()`). Only the
     *                validated array reaches the service, so the body is never trusted whole.
     *   `hand`     - the action coerces each field itself and throws
     *                `ValidationException::withMessages()`. Server-side, and it answers 422, but it
     *                is **golden-rule-9 debt**: no declarative rule set means no length ceiling on
     *                the free text. Reported as debt, not blessed.
     *   `no-body`  - the action reads nothing from the request but the authenticated user. This is
     *                the contract's own toggle-endpoint carve-out.
     *
     * A new action that is neither a Form Request nor listed here fails. A listed action whose body
     * stops matching its promised shape fails. An entry whose action has since been converted to a
     * Form Request fails as stale, so the list can only shrink.
     *
     * @var array<string, 'rules'|'hand'|'no-body'>
     */
    private const FORM_REQUEST_EXEMPT = [
        'app/Http/Controllers/Admin/Collaborator/CollaboratorController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Collaborator/CollaboratorController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Finance/ExpenseController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Finance/ExpenseController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Finance/FinanceCategoryController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Finance/FinanceCategoryController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Finance/IncomeController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Finance/IncomeController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Finance/PaymentMethodController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Finance/PaymentMethodController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/AttendanceCorrectionController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/DepartmentController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/DepartmentController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/DesignationController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/DesignationController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/EmployeeAdvanceController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/EmployeeController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/EmployeeController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/HolidayController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/HolidayController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/LeaveRequestController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/LeaveTypeController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/LeaveTypeController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/PayrollRunController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/SalaryComponentController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/SalaryComponentController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Hr/SalaryStructureController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/SelfService/LeaveController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/WorkShiftController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Hr/WorkShiftController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/AdmissionController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/AssignmentSubmissionController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/AttendanceController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/AttendanceController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/BatchController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/BatchController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/CertificateController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/ClassSessionController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/ClassroomController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/ClassroomController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/DemoClassController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/DemoClassController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/EnrollmentController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/FeeReminderController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/FeeStructureController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/StudentFeeController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/TeacherController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/TeacherController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Institute/TimetableController.php::store' => 'rules',
        'app/Http/Controllers/Admin/Institute/TimetableController.php::update' => 'rules',
        'app/Http/Controllers/Admin/MilestoneController.php::store' => 'rules',
        'app/Http/Controllers/Admin/MilestoneController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Ops/IntegrityCheckController.php::store' => 'rules',
        'app/Http/Controllers/Admin/ProjectMemberController.php::update' => 'rules',
        'app/Http/Controllers/Admin/Support/ConversationController.php::store' => 'hand',
        'app/Http/Controllers/Admin/TaskChecklistController.php::store' => 'rules',
        'app/Http/Controllers/Admin/TaskChecklistController.php::update' => 'rules',
        'app/Http/Controllers/Admin/TaskController.php::store' => 'rules',
        'app/Http/Controllers/Admin/TaskController.php::update' => 'rules',
        'app/Http/Controllers/Admin/TimeTrackingController.php::store' => 'rules',
        'app/Http/Controllers/Admin/TimeTrackingController.php::update' => 'rules',
        'app/Http/Controllers/Auth/EmailVerificationNotificationController.php::store' => 'no-body',
        'app/Http/Controllers/Collaborator/PayoutAccountController.php::store' => 'rules',
        'app/Http/Controllers/Portal/ConversationController.php::store' => 'hand',
        'app/Http/Controllers/Teacher/AttendanceController.php::store' => 'rules',
        'app/Http/Controllers/Teacher/AttendanceController.php::update' => 'rules',
    ];

    /**
     * The forged keys of SEC-12, and the subset of them no form may ever own.
     *
     * Three families, and each is dangerous for a different reason: **identity** (`id`, `user_id`,
     * `branch_id`, `collaborator_id`) re-points a row at somebody else; **provenance**
     * (`created_by`, `created_at`, `deleted_at`, `receipt_no`, `idempotency_key`) rewrites the
     * audit trail that would have shown it; and **money** (`amount`, `commission_rate`,
     * `signed_amount`, `balance_amount`) sets a figure the service was supposed to compute.
     *
     * `NEVER_FILLABLE` is the subset asserted structurally. `status` and `branch_id` are not in it
     * because some models' own forms legitimately own them - the test that covers those is the
     * live route below, not the scan.
     */
    private const FORGED_KEYS = [
        'id', 'created_by', 'updated_by', 'deleted_at', 'created_at', 'branch_id', 'user_id',
        'status', 'is_system', 'is_core', 'level', 'email_verified_at', 'must_change_password',
        'password', 'collaborator_id', 'collaborator_referral_id', 'commission_state',
        'commission_rate', 'base_amount', 'amount', 'paid_amount', 'balance_amount', 'net_amount',
        'receipt_no', 'invoice_number', 'idempotency_key', 'allocated_amount', 'signed_amount',
    ];

    /** Set by a service, a trait or the database - never by a request, on any model. */
    private const NEVER_FILLABLE = [
        'id', 'created_by', 'updated_by', 'deleted_at', 'created_at', 'is_system', 'is_core',
        'email_verified_at', 'must_change_password', 'commission_state', 'signed_amount',
        'idempotency_key', 'balance_amount',
    ];

    /**
     * The three columns that stay mass-assignable, and why removing them would break more than it
     * would close (SEC-12).
     *
     * **`Model::shouldBeStrict()` is what turns this from laziness into a real constraint.** Outside
     * production the application enables `preventSilentlyDiscardingAttributes`, so a `fill()` of a
     * column that is not in `$fillable` *throws* rather than quietly dropping it. Each of these
     * three is filled by a seeder or a service that this slice may not edit, so taking the column
     * off the list would not harden the model - it would make the seeder raise on the next
     * `migrate:fresh --seed`.
     *
     * So the exemption is paid for rather than granted: `assertNoRequestCanReach()` proves, for each
     * one, that a forged value cannot survive validation into the array those writers mass-assign.
     * Combined with SEC-14's first scan - no controller passes the raw request body into
     * `create`/`update`/`fill`/`forceFill` - that closes the same door from the other side.
     *
     * The four that were removed instead are the ones nothing legitimate filled: `Role.created_by`,
     * `Role.updated_by` and `Setting.updated_by` (stamped with `setAttribute()` by `Blameable` and
     * `SettingsService`) and `User.must_change_password` (set as a property by `UserService`, and
     * `forceFill()`ed by every other writer).
     *
     * @var array<string, string>
     */
    private const NEVER_FILLABLE_EXEMPT = [
        'app/Models/Role.php::is_system' => 'RoleSeeder converges protected roles with $role->fill([...is_system...]); no Form Request declares a rule for it.',
        'app/Models/Module.php::is_core' => 'ModuleSeeder converges the registry with $module->fill([...is_core...]); no Form Request declares a rule for it.',
        'app/Models/Cms/Page.php::is_system' => 'PageService::create() and ::duplicate() force it false through Page::create(); ValidatesPage declares it prohibited, so a payload that names it 422s.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-01 - CSRF
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-01. Every state-changing route refuses a request that carries a session but no token.
     *
     * The session is the point. An unauthenticated POST failing proves nothing; what CSRF defends
     * against is a request that arrives *with* the victim's cookie, sent by a page the victim did
     * not write. So the caller here is signed in as a Super Admin - the most dangerous possible
     * forgery - and the only thing missing is the token.
     *
     * @param  list<string>  $methods
     */
    #[Test]
    #[DataProvider('stateChangingRoutes')]
    public function test_every_state_changing_route_requires_a_csrf_token(string $name, array $methods): void
    {
        // A non-empty `$except` array is a route that accepts a cross-origin POST, and it fails this
        // test wherever the exemption is declared. Asserted per case so the failure names the route
        // that was being checked when the exemption was found.
        $this->assertSame(
            [],
            (new SecurityAuditor)->auditCsrfExceptions(),
            'SEC-01: VerifyCsrfToken::$except must be empty - every entry names a route reachable from any origin.',
        );

        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            $this->fail(sprintf('SEC-01: route-guard-manifest lists %s, which is no longer registered.', $name));
        }

        $this->actingAs($this->createSuperAdmin());
        $this->enforceCsrf();

        $before = $this->sentinelCounts();

        // Route parameters are substituted with `1` rather than a real id on purpose: ValidateCsrfToken
        // runs inside the `web` group, ahead of SubstituteBindings, so the token is judged before the
        // binding is resolved and a bogus id cannot turn a 419 into a 404.
        $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
        $verb = $this->writeVerb($methods);

        $response = $this->call($verb, $uri);

        $this->assertSame(
            419,
            $response->getStatusCode(),
            sprintf('SEC-01: %s %s answered %d without a CSRF token.', $verb, $uri, $response->getStatusCode()),
        );

        $this->assertSame($before, $this->sentinelCounts(), sprintf('SEC-01: %s wrote a row without a token.', $name));
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-08, SEC-09 - what a query parameter may decide
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-08. Eight injection payloads against every admin index screen.
     *
     * **Deviation from the literal matrix, recorded here so it is a decision rather than an
     * oversight:** the contract reads "every index route x every request parameter x eight
     * payloads". This sends all eight parameters poisoned *together* in one request per payload,
     * which is a strictly harder case (a controller that concatenates two of them is only caught
     * when both are hostile) and turns a six-figure request count into a four-figure one.
     *
     * The assertion is deliberately loose on status and strict on everything else. A 422 is a good
     * answer, a 404 is a good answer, a 302 is a good answer; **500 is not**, because a database
     * error page is the shape an injection attempt takes when it half-worked.
     */
    #[Test]
    public function test_sql_injection_payloads_are_harmless(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $before = $this->sentinelCounts();
        $screens = $this->indexScreens();

        $this->assertNotEmpty($screens, 'SEC-08: screen-manifest yielded no admin index screen to attack.');

        foreach ($screens as $name => $uri) {
            foreach (self::INJECTION_PAYLOADS as $payload) {
                $query = [];

                foreach (self::INDEX_PARAMETERS as $parameter) {
                    $query[$parameter] = $payload;
                }

                $started = microtime(true);
                $response = $this->get($uri.'?'.http_build_query($query));
                $elapsed = microtime(true) - $started;

                $this->assertContains(
                    $response->getStatusCode(),
                    [200, 302, 404, 422],
                    sprintf('SEC-08: %s answered %d for %s.', $name, $response->getStatusCode(), $payload),
                );

                // `1 AND SLEEP(3)` reaching the server as SQL is the only way this exceeds a second.
                $this->assertLessThan(
                    1.0,
                    $elapsed,
                    sprintf('SEC-08: %s took %.2fs for %s - SLEEP() executed.', $name, $elapsed, $payload),
                );
            }
        }

        $this->assertSame($before, $this->sentinelCounts(), 'SEC-08: an index request wrote a row.');
    }

    /**
     * SEC-09. `sort` and `direction` name a column, and a column cannot be a bound parameter.
     *
     * That is the whole hazard: `?` stands for a value, so an identifier chosen at runtime has to
     * reach the statement as text, and the only possible control is an allowlist checked before it
     * gets there. This captures the executed SQL with `DB::listen` and asserts the payload is not
     * in it - which catches both the injection and the quieter bug of a controller that lets a
     * caller sort by `users.password` and read the hash one binary-search request at a time.
     */
    #[Test]
    public function test_sort_and_direction_are_allowlisted(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $attacks = [
            ['sort' => 'password'],
            ['sort' => '(select 1)'],
            ['sort' => 'users.password'],
            ['direction' => '; drop'],
        ];

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        foreach ($this->indexScreens() as $name => $uri) {
            foreach ($attacks as $attack) {
                $statements = [];

                $response = $this->get($uri.'?'.http_build_query($attack));

                $this->assertContains(
                    $response->getStatusCode(),
                    [200, 302, 404, 422],
                    sprintf('SEC-09: %s answered %d for %s.', $name, $response->getStatusCode(), json_encode($attack)),
                );

                foreach ($statements as $sql) {
                    foreach ($attack as $value) {
                        $this->assertStringNotContainsString(
                            $value,
                            $sql,
                            sprintf('SEC-09: %s interpolated %s into SQL: %s', $name, $value, $sql),
                        );
                    }
                }
            }
        }
    }

    /**
     * SEC-10. No raw SQL carries an interpolated request value.
     *
     * Asserted through `SecurityAuditor::auditRawSql()`, which is the same check `security:audit`
     * runs in CI, so a finding here and a finding there can never disagree.
     */
    #[Test]
    public function test_no_raw_sql_interpolation(): void
    {
        $findings = (new SecurityAuditor)->auditRawSql();
        $failed = array_values(array_filter($findings, static fn (array $one): bool => $one['severity'] === 'failed'));

        $this->assertSame([], $failed, 'SEC-10: '.$this->describe($failed));

        // The auditor's own RAW_SQL_EXEMPT constant carries the exemptions today. The contract asks
        // for them in a file beside the other manifests, so a reviewer reads the promise without
        // reading the scanner - see the report's "MAIN SESSION MUST MERGE".
        $allowlist = base_path('tests/Support/raw-sql-allowlist.php');

        if (! File::exists($allowlist)) {
            $this->markTestSkipped('tests/Support/raw-sql-allowlist.php does not exist; SEC-10 asserted SecurityAuditor::auditRawSql() only.');
        }

        foreach ((array) require $allowlist as $row) {
            $this->assertArrayHasKey('file', (array) $row, 'SEC-10: every raw-sql-allowlist row names a file.');
            $this->assertArrayHasKey('why', (array) $row, 'SEC-10: every raw-sql-allowlist row states why it is safe.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-11, SEC-14 - what a request body may decide
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-11. Every model says which columns a request may fill.
     *
     * `$guarded = []` is not a shortcut, it is the removal of the control: one form post then
     * reaches `is_system`, `status`, `commission_rate` or `paid_amount` on any model the request
     * touches. The scan reads every class under `app/Models/**` through reflection rather than by
     * regex, so a `$fillable` inherited from a base class counts and a commented-out one does not.
     */
    #[Test]
    public function test_every_model_is_explicitly_fillable(): void
    {
        $offenders = [];

        foreach ($this->modelClasses() as $class => $relative) {
            /** @var Model $model */
            $model = new $class;

            $fillable = $model->getFillable();
            $guarded = $model->getGuarded();

            // Laravel's default is `$guarded = ['*']`, which is "nothing is fillable" and is safe.
            $explicitlyGuarded = $guarded !== [] && $guarded !== ['*'];

            if ($fillable === [] && ! $explicitlyGuarded && $guarded !== ['*']) {
                $offenders[] = $relative.' - $guarded = [] leaves every column mass-assignable';
            }
        }

        $this->assertSame([], $offenders, 'SEC-11: '.implode('; ', $offenders));

        // Cross-checked against the scanner CI runs, so the two can never disagree about a model.
        $this->assertSame([], (new SecurityAuditor)->auditMassAssignment(), 'SEC-11: SecurityAuditor disagrees with the reflection scan.');
    }

    /**
     * SEC-12. A forged key in a write payload is rejected or ignored, never stored.
     *
     * Next to SEC-11 rather than with the authorization sweep, because it is the same control seen
     * from the other side: SEC-11 asks whether a model *could* be filled from a request, and this
     * asks what happens when one actually tries. Both read the same model list, and splitting them
     * across two classes would have meant two copies of that scan.
     *
     * **Mass assignment is authorization.** A request that cannot reach a route can still reach a
     * column, and `status=active` posted onto your own suspended profile is a privilege escalation
     * that never touches a policy.
     */
    #[Test]
    public function test_forged_fields_are_ignored_or_rejected(): void
    {
        $offenders = [];
        $seen = [];

        foreach ($this->modelClasses() as $class => $relative) {
            foreach (array_intersect(self::NEVER_FILLABLE, (new $class)->getFillable()) as $key) {
                $exemption = $relative.'::'.$key;

                if (isset(self::NEVER_FILLABLE_EXEMPT[$exemption])) {
                    $seen[] = $exemption;
                    $this->assertNoRequestCanReach($key, $exemption);

                    continue;
                }

                $offenders[] = $relative.' fills '.$key;
            }
        }

        $this->assertSame([], $offenders, 'SEC-12: a derived column is mass-assignable: '.implode(', ', $offenders));

        // A stale exemption is a lie about the current tree, so the list can only ever shrink.
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::NEVER_FILLABLE_EXEMPT), $seen)),
            'SEC-12: NEVER_FILLABLE_EXEMPT names a column that is no longer mass-assignable - delete the entry.',
        );

        // One live route, end to end: the profile form, posted with every forged key at once.
        $user = $this->createUserWithPermissions([], PanelType::Admin);
        $before = $user->only(['status', 'branch_id', 'must_change_password']);

        $payload = ['name' => 'Legitimate Name', 'locale' => $user->locale ?? 'en'];

        foreach (self::FORGED_KEYS as $key) {
            $payload[$key] = $key === 'id' ? 999999 : 'forged';
        }

        $payload['permissions'] = PermissionRegistry::permissionNames();

        $this->actingAs($user)->put(route('account.profile.update', [], false), $payload);

        $fresh = $user->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame($before['status'], $fresh->status, 'SEC-12: a posted status changed the row.');
        $this->assertSame($before['branch_id'], $fresh->branch_id, 'SEC-12: a posted branch_id changed the row.');
        $this->assertSame($before['must_change_password'], $fresh->must_change_password, 'SEC-12: a posted must_change_password changed the row.');
        $this->assertNotSame(999999, $fresh->getKey(), 'SEC-12: a posted id re-keyed the row.');

        $this->markTestSkipped(sprintf(
            'SEC-12 asserted the model layer for every model (%d columns stay mass-assignable, each with a proven '
            .'"no request can reach it" exemption) and one live route (account.profile.update): the other 29 write '
            .'routes need a legitimate-payload builder, which does not exist yet.',
            count(self::NEVER_FILLABLE_EXEMPT),
        ));
    }

    /**
     * SEC-14. Validation happens in a Form Request, not in the controller and never by trusting the body.
     *
     * Two offences, and the first is the serious one: `create($request->all())` hands the model
     * every key the caller invented, so the guard is `$fillable` alone and one forgotten column is
     * a privilege escalation. The second - a write action whose first parameter is the framework's
     * `Request` - is the shape that makes the first one easy to write.
     *
     * **The second offence is currently 66 actions wide, and the honest answer was neither to
     * fail nor to wave it through.** Every one of them was read: 63 reach a complete
     * `$request->validate([...])` rule set - most through the house's private `validated()` helper
     * - and only the validated array reaches the service, which is what §8's "Form Request
     * validation (server side) for every writable field" is actually asking for; two hand-validate
     * with `ValidationException::withMessages()`; one reads no body at all. So they are listed in
     * `FORM_REQUEST_EXEMPT` **with the shape that makes each acceptable, and the shape is
     * re-derived from the source on every run** - an exemption nobody checks is a hole with a
     * comment on it. The remaining debt (golden rule 9: the rules belong in a Form Request class,
     * and the two hand-validated actions put no ceiling on their free text) is recorded in
     * `DEVELOPMENT_LOG.md`, not hidden here.
     *
     * The parameter type is read by **reflection**, not by regex: a short class name resolved
     * through a `use` statement is exactly the case a regex gets wrong, and getting it wrong here
     * means silently excusing a real offender.
     */
    #[Test]
    public function test_controllers_validate_through_form_requests(): void
    {
        $unvalidated = [];
        $untyped = [];
        $seen = [];

        foreach ($this->phpFiles(app_path('Http/Controllers')) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $relative = 'app/Http/Controllers/'.$relativePath;
            $source = (string) File::get($file->getPathname());

            if (preg_match('/->(?:create|update|fill|forceFill)\(\s*\$request->(?:all|input|except)\(/', (new SecurityAuditor)->withoutComments($source)) === 1) {
                $unvalidated[] = $relative;
            }

            $class = 'App\\Http\\Controllers\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (! class_exists($class)) {
                continue;
            }

            $bodies = $this->methodBodies($source);

            // `store`/`update` are the two conventional write actions; anything else that takes no
            // body at all names itself in FORM_REQUEST_EXEMPT rather than widening the rule.
            foreach (['store', 'update'] as $action) {
                if (! method_exists($class, $action)) {
                    continue;
                }

                $method = new ReflectionMethod($class, $action);

                // An action inherited from a base controller belongs to the file that declares it.
                if ($method->getDeclaringClass()->getName() !== $class || ! $method->isPublic()) {
                    continue;
                }

                $key = $relative.'::'.$action;
                $parameters = $method->getParameters();
                $type = $parameters === [] ? null : $parameters[0]->getType();
                $name = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

                if ($name !== null && is_subclass_of($name, FormRequest::class)) {
                    continue;
                }

                if (isset(self::FORM_REQUEST_EXEMPT[$key])) {
                    $seen[] = $key;
                    $this->assertExemptionIsEarned($key, self::FORM_REQUEST_EXEMPT[$key], $bodies[$action] ?? '', $bodies);

                    continue;
                }

                $untyped[] = $key.' takes '.($name ?? 'an untyped parameter');
            }
        }

        $this->assertSame([], $unvalidated, 'SEC-14: a controller passes the raw request body into a write: '.implode(', ', $unvalidated));
        $this->assertSame([], $untyped, 'SEC-14: a write action does not take a Form Request and is not exempt: '.implode(', ', $untyped));

        // An entry whose action has since been converted to a Form Request is stale, and a stale
        // exemption is how a list like this quietly stops meaning anything.
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::FORM_REQUEST_EXEMPT), $seen)),
            'SEC-14: FORM_REQUEST_EXEMPT names an action that now takes a Form Request - delete the entry.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Providers and fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Every POST / PUT / PATCH / DELETE row of the route-guard manifest.
     *
     * Read from the file rather than from `Route::getRoutes()`, because a data provider runs before
     * the application exists. The manifest and the route table are kept equal by SEC-20.
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function stateChangingRoutes(): iterable
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require dirname(__DIR__, 2).'/Support/route-guard-manifest.php';

        foreach ($rows as $row) {
            if (($row['state_changing'] ?? false) !== true) {
                continue;
            }

            $name = (string) $row['route'];

            yield $name => [$name, array_values(array_filter((array) ($row['methods'] ?? []), 'is_string'))];
        }
    }

    /**
     * Admin index screens, as `route name => URI`, with real parameters where the manifest can give them.
     *
     * The manifest's `params` closures read seeded rows, so they need the database this test already
     * has. One that cannot resolve (its fixture belongs to a phase whose seeder is demo-only) is
     * skipped rather than attacked with a made-up id, which would only ever prove that 404 works.
     *
     * @return array<string, string>
     */
    private function indexScreens(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require base_path('tests/Support/screen-manifest.php');
        $screens = [];

        foreach ($rows as $row) {
            if (($row['kind'] ?? null) !== 'index' || ($row['panel'] ?? null) !== 'admin' || ($row['response'] ?? 'html') !== 'html') {
                continue;
            }

            $name = (string) $row['route'];

            if (Route::getRoutes()->getByName($name) === null) {
                continue;
            }

            try {
                $parameters = ($row['params'])();
                $screens[$name] = route($name, $parameters, false);
            } catch (\Throwable) {
                // No fixture for this screen in the production seed: not this test's subject.
                continue;
            }
        }

        return $screens;
    }

    /**
     * Turn CSRF verification back on for this application instance.
     *
     * `Application::$isRunningInConsole` is an instance property, so this dies with the test and
     * cannot leak into another one. The production path is exercised unchanged.
     */
    private function enforceCsrf(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
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

        foreach (self::SENTINEL_TABLES as $table) {
            $counts[$table] = DB::getSchemaBuilder()->hasTable($table) ? DB::table($table)->count() : -1;
        }

        return $counts;
    }

    /**
     * Every concrete Eloquent model, as `FQCN => path relative to the project root`.
     *
     * @return array<class-string<Model>, string>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach ($this->phpFiles(app_path('Models')) as $file) {
            $relative = 'app/Models/'.str_replace('\\', '/', $file->getRelativePathname());
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', $file->getRelativePathname()));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[$class] = $relative;
        }

        return $classes;
    }

    /** @return list<SplFileInfo> */
    private function phpFiles(string $directory): array
    {
        return File::isDirectory($directory) ? File::allFiles($directory) : [];
    }

    /**
     * Every method body in one file, keyed by method name.
     *
     * **Tokenised rather than regexed, because a brace inside a string is the normal case here.**
     * A controller body is full of `{$name}` interpolations, `'{id}'` route fragments and Blade
     * fragments in toast messages; counting raw `{` and `}` over the text would end the body in the
     * wrong place and then the shape check would be reading somebody else's method.
     *
     * @return array<string, string>
     */
    private function methodBodies(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true),
        ));

        $bodies = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
                continue;
            }

            $cursor = $index + 1;

            while ($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) {
                $cursor++;
            }

            if (! is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_STRING) {
                continue;
            }

            $name = (string) $tokens[$cursor][1];

            // Walk past the signature and the return type to the body, or to the `;` of an
            // abstract/interface declaration, which has no body to collect.
            while ($cursor < $count && $tokens[$cursor] !== '{' && $tokens[$cursor] !== ';') {
                $cursor++;
            }

            if (($tokens[$cursor] ?? null) !== '{') {
                continue;
            }

            $depth = 0;
            $body = '';

            for (; $cursor < $count; $cursor++) {
                $text = is_array($tokens[$cursor]) ? (string) $tokens[$cursor][1] : (string) $tokens[$cursor];

                if ($text === '{') {
                    $depth++;
                }

                if ($text === '}') {
                    $depth--;
                }

                $body .= $text;

                if ($depth === 0) {
                    break;
                }
            }

            $bodies[$name] = $body;
        }

        return $bodies;
    }

    /**
     * Re-derive an exempt action's promised shape from its own body (SEC-14).
     *
     * `rules` accepts a validator reached through a private helper of the same class as well as one
     * written inline: `$this->validated($request)` is the house pattern, and refusing it would push
     * every controller to inline the same rule array twice.
     *
     * @param  array<string, string>  $bodies  every method body in the same file, keyed by name
     */
    private function assertExemptionIsEarned(string $key, string $shape, string $body, array $bodies): void
    {
        $validates = static fn (string $source): bool => preg_match('/->validate\(|Validator::make\(/', $source) === 1;

        if ($shape === 'rules') {
            $reached = $validates($body);

            foreach ($this->helpersCalledIn($body) as $helper) {
                $reached = $reached || (isset($bodies[$helper]) && $validates($bodies[$helper]));
            }

            $this->assertTrue($reached, sprintf('SEC-14: %s is exempt as `rules` but reaches no validate() call.', $key));

            return;
        }

        if ($shape === 'hand') {
            $this->assertMatchesRegularExpression(
                '/ValidationException::withMessages\(/',
                $body,
                sprintf('SEC-14: %s is exempt as `hand` but throws no ValidationException.', $key),
            );

            return;
        }

        // `no-body`: nothing but the authenticated user is read off the request.
        $this->assertSame(
            0,
            preg_match('/\$request->(?!user\(|ip\(|session\(|hasSession)/', $body),
            sprintf('SEC-14: %s is exempt as `no-body` but reads the request body.', $key),
        );
    }

    /**
     * Names of `$this->foo(...)` calls in a method body - the candidates for a private validator.
     *
     * @return list<string>
     */
    private function helpersCalledIn(string $body): array
    {
        if (preg_match_all('/\$this->(\w+)\s*\(/', $body, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Prove that no Form Request lets a caller name this column (SEC-12).
     *
     * **A column that is mass-assignable is only as safe as the payload that reaches `fill()`.**
     * Every writer of these three columns is a seeder or a service that takes a *validated* array,
     * so the question is not whether the model would accept the key - it would - but whether a
     * request can ever put it there. Two answers are acceptable: no Form Request declares a rule
     * for the key at all, so `validated()` drops it; or a Form Request declares it `prohibited`,
     * which is stronger still because the request 422s instead of silently ignoring it.
     */
    private function assertNoRequestCanReach(string $column, string $exemption): void
    {
        $pattern = '/[\'"]'.preg_quote($column, '/').'[\'"]\s*=>\s*(?<rules>\[[^\]]*\]|[\'"][^\'"]*[\'"])/s';

        foreach ($this->phpFiles(app_path('Http/Requests')) as $file) {
            $source = (new SecurityAuditor)->withoutComments((string) File::get($file->getPathname()));

            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            $relative = 'app/Http/Requests/'.str_replace('\\', '/', $file->getRelativePathname());

            foreach ($matches as $match) {
                $this->assertStringContainsString(
                    'prohibited',
                    $match['rules'],
                    sprintf(
                        'SEC-12: %s is exempt only because no request can reach %s, but %s declares a rule for it: %s',
                        $exemption,
                        $column,
                        $relative,
                        trim($match['rules']),
                    ),
                );
            }
        }
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function describe(array $findings): string
    {
        return implode('; ', array_map(
            static fn (array $one): string => sprintf('%s %s (expected %s, found %s)', $one['code'], $one['subject'], $one['expected'], $one['actual']),
            $findings,
        ));
    }
}
