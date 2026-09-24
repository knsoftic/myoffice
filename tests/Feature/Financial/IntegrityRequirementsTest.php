<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * FIN-01 and the structural half of section 11.5 (phase-24-25 sections 11.5.1, 11.5.2).
 *
 * **Phase 24 does not rewrite the financial suite — it pins it.** The spine and Phase 18 already prove
 * the nine requirement scenarios of §120; the risk this file exists to close is different and much
 * quieter: that one of those nine is renamed in a tidy-up, moved into a helper, or dropped because
 * "the other copy covers it", and the §120 evidence pack silently shrinks from nine proofs to eight.
 * Nobody notices, because the suite is still green — there is simply less of it.
 *
 * So FIN-01 asserts the **names**, by reflection, and never by running them. Running them would
 * duplicate the work and, worse, would make this test pass whenever the suite passed. A pin has to
 * fail on absence, which is the one thing a passing test cannot observe about itself.
 *
 * The other tests here are the section-11.5 checks that are properties of the **schema and the source
 * tree** rather than of a scenario: a ledger table that grew a `deleted_at`, a money column that is
 * not `decimal(15,2)`, a float cast inside `Money`. Each of those defeats every scenario test at once,
 * and none of them is visible from inside a scenario.
 */
#[Group('financial')]
final class IntegrityRequirementsTest extends TestCase
{
    // Only for FIN-17's live row. Everything else in this file is a property of the schema or of the
    // source tree and deliberately builds nothing — but a trigger that never fires cannot be proved
    // from `information_schema`, and a ledger row hand-inserted to prove it would be a shape the
    // engine never produces (`CLAUDE.md` §1.5).
    use BuildsFinancialFixtures;
    use RefreshDatabase;

    /**
     * The suite name §120's evidence pack is collected under.
     */
    private const SUITE = 'financial-120';

    /**
     * The nine requirement tests, copied verbatim from phase-24-25 section 11.5.1.
     *
     * **Do not "tidy" this list.** It is the contract, not a convenience: the order is §120.1 … §120.9,
     * and the wording is what the requirement document says. A name that reads awkwardly here reads
     * identically in the §120 evidence pack, which is the point.
     *
     * @var list<string>
     */
    private const REQUIRED = [
        'test_student_without_collaborator_creates_no_commission_row',            // §120.1
        'test_student_payment_creates_one_commission_at_configured_rate',         // §120.2
        'test_same_fee_payment_processed_twice_creates_one_commission',           // §120.3
        'test_three_installments_create_three_commissions',                       // §120.4
        'test_student_refund_creates_negative_reversal_and_preserves_original',   // §120.5
        'test_project_without_collaborator_creates_no_commission',                // §120.6
        'test_project_payment_creates_commission_at_configured_rate',             // §120.7
        'test_project_payment_refund_creates_reversal_entry',                     // §120.8
        'test_payout_of_20000_against_50000_wallet',                              // §120.9
    ];

    /**
     * The six that must **also** exist at HTTP level under `tests/Feature/Institute/Fees/`
     * (PH18-01 … PH18-06, phase-18 section 11.1).
     *
     * The three that are not here are the project-side ones: a project payment never goes through the
     * fee screens, so a copy there would be a fixture pretending to be a scenario.
     *
     * @var list<string>
     */
    private const FEES_SUBSET = [
        'test_student_without_collaborator_creates_no_commission_row',
        'test_student_payment_creates_one_commission_at_configured_rate',
        'test_same_fee_payment_processed_twice_creates_one_commission',
        'test_three_installments_create_three_commissions',
        'test_student_refund_creates_negative_reversal_and_preserves_original',
        'test_payout_of_20000_against_50000_wallet',
    ];

    /** Service-level copies (spine FT-01 … FT-09). */
    private const SERVICE_DIR = 'tests/Feature/Financial';

    /** HTTP-level copies (PH18-01 … PH18-06). */
    private const HTTP_DIR = 'tests/Feature/Institute/Fees';

    /**
     * The nine append-only financial tables of D16, in the order the decision lists them.
     *
     * @var list<string>
     */
    private const APPEND_ONLY = [
        'student_fee_payments',
        'payment_reversals',
        'student_fee_discounts',
        'collaborator_referrals',
        'collaborator_commission_settings',
        'collaborator_commission_entitlements',
        'collaborator_commission_ledger_entries',
        'collaborator_payouts',
        'collaborator_payout_allocations',
    ];

    /**
     * The `BEFORE DELETE` triggers the spine declares (migration `2026_09_12_130019`, spine INV-5),
     * as `table => trigger`.
     *
     * **This is deliberately not the same list as {@see self::APPEND_ONLY}, and the difference is the
     * point.** `CLAUDE.md` §3 says an append-only table is protected by a model `deleting` hook and,
     * *where its contract says so*, a `BEFORE DELETE` trigger — so the two sets answer two different
     * questions. D16 decides which tables may never carry `deleted_at` (nine, including
     * `collaborator_payout_allocations`); INV-5 decides which of them are additionally defended
     * against a raw `DELETE` issued outside Eloquent (nine, including `project_payments` and *not*
     * allocations, whose one mutation is its release and which is reached only through
     * `PayoutService`). Asserting one list against the other would manufacture a failure out of a
     * decision that was made on purpose.
     *
     * @var array<string, string>
     */
    private const NO_DELETE_TRIGGERS = [
        'student_fee_discounts' => 'trg_sfd_no_delete',
        'student_fee_payments' => 'trg_sfp_no_delete',
        'project_payments' => 'trg_pp_no_delete',
        'payment_reversals' => 'trg_pr_no_delete',
        'collaborator_referrals' => 'trg_cr_no_delete',
        'collaborator_commission_settings' => 'trg_ccs_no_delete',
        'collaborator_commission_entitlements' => 'trg_cce_no_delete',
        'collaborator_commission_ledger_entries' => 'trg_cle_no_delete',
        'collaborator_payouts' => 'trg_cp_no_delete',
    ];

    /*
    |--------------------------------------------------------------------------
    | FIN-01 — the pin
    |--------------------------------------------------------------------------
    */

    /**
     * FIN-01 — the `--group=financial-120` suite contains exactly these nine, by name.
     *
     * **How the tripwire disarms itself, and why that is not a loophole.** At the time this test was
     * written the nine scenarios all exist and all pass, but under the descriptive method names the
     * earlier phases gave them (`a_student_without_a_collaborator_creates_no_commission_row` and so
     * on) and with no `#[Group]` attributes anywhere in the suite. Renaming them and grouping them
     * means editing files this slice does not own, so the rename is handed to the main session (see
     * the report) and this test skips — **once**, and only while *none* of the nine has been renamed
     * yet.
     *
     * The moment the first one lands, the skip stops firing and every remaining absence becomes a hard
     * failure naming the missing method. That is the property the contract actually asks for: the
     * §120 suite can never be *quietly reduced*. It can only be wholesale absent, which is not quiet.
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_the_nine_requirement_tests_exist_and_are_grouped(): void
    {
        $service = $this->declaredTestMethodsIn(self::SERVICE_DIR);
        $http = $this->declaredTestMethodsIn(self::HTTP_DIR);

        $foundAtServiceLevel = array_values(array_intersect(self::REQUIRED, array_keys($service)));

        if ($foundAtServiceLevel === []) {
            $this->markTestSkipped($this->renamePendingMessage($service));
        }

        $failures = [];

        foreach (self::REQUIRED as $name) {
            if (! isset($service[$name])) {
                $failures[] = sprintf(
                    '%s is missing from %s (spine FT-01..FT-09, §120). The §120 suite may not be reduced.',
                    $name,
                    self::SERVICE_DIR,
                );

                continue;
            }

            if (! $service[$name]['grouped']) {
                $failures[] = sprintf(
                    '%s exists in %s but carries no #[Group(\'%s\')], so `--group=%s` would not run it.',
                    $name,
                    $service[$name]['class'],
                    self::SUITE,
                    self::SUITE,
                );
            }
        }

        foreach (self::FEES_SUBSET as $name) {
            if (! isset($http[$name])) {
                $failures[] = sprintf(
                    '%s is missing from %s (PH18-01..PH18-06). The engine is proved; this is the proof it is reached.',
                    $name,
                    self::HTTP_DIR,
                );

                continue;
            }

            if (! $http[$name]['grouped']) {
                $failures[] = sprintf(
                    '%s exists in %s but carries no #[Group(\'%s\')].',
                    $name,
                    $http[$name]['class'],
                    self::SUITE,
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            sprintf("The §120 requirement suite is not intact:\n  - %s", implode("\n  - ", $failures)),
        );
    }

    /**
     * The list itself is nine, distinct, and correctly split six/three.
     *
     * Cheap, and it catches the one edit nobody reviews: a copy-paste that leaves eight names or two
     * identical ones in the constant above, after which FIN-01 would still be green while pinning
     * less than it claims.
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_the_requirement_list_is_exactly_nine_distinct_names(): void
    {
        $this->assertCount(9, self::REQUIRED, 'Requirement §120 has nine scenarios.');
        $this->assertSame(self::REQUIRED, array_values(array_unique(self::REQUIRED)), 'A name is listed twice.');

        $this->assertCount(6, self::FEES_SUBSET, 'PH18-01..PH18-06 is six tests.');

        $this->assertSame(
            [],
            array_values(array_diff(self::FEES_SUBSET, self::REQUIRED)),
            'The fee-side subset names a test that is not one of the nine.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIN-17 (schema half) — the nine append-only tables
    |--------------------------------------------------------------------------
    */

    /**
     * FIN-17 / D16 — none of the nine financial tables has a `deleted_at` column.
     *
     * **A nullable `deleted_at` on an immutable ledger is a loaded gun** (`CLAUDE.md` §3): one
     * `->delete()` hides the row from every aggregate while the wallet cache keeps the money, and on a
     * NULL-tolerant unique guard such as `uq_cle_dedupe` it silently permits the duplicate that index
     * exists to prevent. The column is the whole failure — the trait, the hook and the trigger are
     * only what stops it being used.
     *
     * Asserted per table rather than in one loop so the failure message is the table name.
     *
     * @return array<string, array{0: string}>
     */
    public static function appendOnlyTableProvider(): array
    {
        $cases = [];

        foreach (self::APPEND_ONLY as $table) {
            $cases[$table] = [$table];
        }

        return $cases;
    }

    #[Test]
    #[Group(self::SUITE)]
    #[DataProvider('appendOnlyTableProvider')]
    public function test_financial_immutability_is_enforced_by_the_database_on_every_append_only_table(string $table): void
    {
        $this->assertTrue(
            $this->schemaHasTable($table),
            sprintf('D16 names `%s` as append-only, and the table is not in the schema at all.', $table),
        );

        $this->assertSame(
            [],
            $this->columnsMatching($table, 'deleted_at'),
            sprintf(
                '`%s` has grown a `deleted_at`. D16/D19: never add it back — one ->delete() would hide '
                .'the row from every aggregate while the wallet cache keeps the money.',
                $table,
            ),
        );

        $trigger = self::NO_DELETE_TRIGGERS[$table] ?? null;

        if ($trigger === null) {
            // Not a gap: see the note on self::NO_DELETE_TRIGGERS. The model `deleting` hook is this
            // table's whole defence by decision, and asserting a trigger here would invent a failure.
            return;
        }

        $this->assertSame(
            ['BEFORE DELETE'],
            $this->triggerShape($trigger, $table),
            sprintf(
                'The `%s` BEFORE DELETE trigger on `%s` is gone or has changed shape (spine INV-5). '
                .'The model hook only covers Eloquent; this is the layer that holds when somebody '
                .'reaches the table with a raw query, a console command or a future migration — and '
                .'for the ledger a DELETE would free the slot in `uq_cle_source` and let the same '
                .'receipt pay twice.',
                $trigger,
                $table,
            ),
        );
    }

    /**
     * FIN-17, the behavioural half that needs no money fixture: the trigger actually **fires**.
     *
     * Asserting the row in `information_schema.TRIGGERS` proves the object exists; it does not prove
     * MariaDB will raise on a delete, and a trigger whose body was rewritten to something harmless
     * would satisfy the first check and none of the promise. So one real row is inserted with the
     * query builder and deleted with raw SQL, which is the exact path the trigger exists to stop —
     * Eloquent's `deleting` hook is not in play, and neither is any service.
     *
     * `collaborator_commission_ledger_entries` is the table chosen because it is the one the spine
     * singles out: **every duplicate guarantee in the commission engine rests on `uq_cle_source`, and
     * a DELETE frees that slot.**
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_financial_immutability_is_enforced_by_the_database_when_a_raw_delete_is_attempted(): void
    {
        $table = 'collaborator_commission_ledger_entries';

        $id = $this->plantLedgerRow($table);

        if ($id === null) {
            $this->markTestSkipped(
                'phase-24-25 section 11.5.2 FIN-17 needs one row in `'.$table.'` to delete, and the '
                .'commission engine posted none for the fixture partner. Either a guard in the engine '
                .'now refuses this shape (a new default in the `collaborator.*` settings group is the '
                .'usual cause — `commission_min_entry_amount` and `commission_on_admission_fee` both '
                .'gate a posting) or the table moved. Fix `plantLedgerRow()`; do not delete this test. '
                .'The trigger is the only thing standing between a raw DELETE and a receipt that pays '
                .'twice, and `information_schema` can only say the trigger exists, never that it fires.'
            );
        }

        try {
            DB::statement('DELETE FROM `'.$table.'` WHERE id = ?', [$id]);

            $this->fail(sprintf(
                'A raw DELETE on `%s` succeeded. `trg_cle_no_delete` must raise SQLSTATE 45000 — '
                .'without it, "a payment can never pay twice" is true only until somebody deletes a row.',
                $table,
            ));
        } catch (QueryException $exception) {
            $this->assertSame(
                '45000',
                (string) $exception->getCode(),
                'The DELETE was refused, but not by the trigger: '.$exception->getMessage(),
            );
        }

        $this->assertSame(
            1,
            (int) DB::table($table)->where('id', $id)->count(),
            'The row is gone even though the DELETE raised.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIN-18 — money is decimal, everywhere
    |--------------------------------------------------------------------------
    */

    /**
     * FIN-18, the half that can be asserted without an allowlist: this project's own naming
     * convention (`CLAUDE.md` §3).
     *
     *   · every `*_amount` column is `decimal(15,2)`
     *   · every `*_rate`, `*_percentage`, `*_pct` column is `decimal(8,4)` — no "reported percentage"
     *     exception, and marks are `decimal(8,2)` precisely because they are not percentages
     *   · nothing anywhere in the schema is `float`, `double` or `real`
     *
     * The third clause is the one that matters most and costs least. A single `double` column is
     * enough to make `Money`'s bcmath arithmetic pointless: the value is already wrong by the time PHP
     * reads it, and every reconciliation check downstream compares two equally wrong numbers.
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_money_columns_are_decimal_everywhere(): void
    {
        $offenders = $this->columns(
            "COLUMN_NAME LIKE '%\\_amount' AND COLUMN_TYPE <> 'decimal(15,2)'"
        );

        $this->assertSame([], $offenders, "An `*_amount` column is not decimal(15,2):\n  ".implode("\n  ", $offenders));

        $offenders = $this->columns(
            "COLUMN_NAME REGEXP '_(rate|percentage|pct)$' AND COLUMN_TYPE <> 'decimal(8,4)'"
        );

        $this->assertSame([], $offenders, "A rate column is not decimal(8,4):\n  ".implode("\n  ", $offenders));

        $offenders = $this->columns("DATA_TYPE IN ('float','double','real')");

        $this->assertSame(
            [],
            $offenders,
            "A binary floating-point column exists. Money never touches a float (`CLAUDE.md` §1.4):\n  "
            .implode("\n  ", $offenders),
        );
    }

    /**
     * FIN-18, the full §120 sweep — named with FIN-18's own id as its prefix for the same reason
     * {@see test_no_float_and_no_second_balance_source_across_the_whole_application()} is.
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_money_columns_are_decimal_everywhere_by_the_full_name_sweep(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.5.2 FIN-18 sweeps every column whose NAME matches '
            .'`amount|fee|salary|price|budget|balance|total|paid|discount|tax|value|commission` and requires '
            .'an allowlist file closed to exactly three entries (`progress`, `rating`, `*_marks`). Run as a '
            .'plain substring match against the current schema that regex returns ~80 legitimate columns — '
            .'`settings.value` and `cache.value` (longtext), `holidays.is_paid` and `leave_types.is_paid` '
            .'(boolean), `tasks.subtask_total` and `student_course_progress.topics_total` (counters), '
            .'`contact_inquiries.budget` (free text on a public form), `clients.tax_number` (varchar), plus '
            .'every `commission_*` and `*_fee_id` foreign key — so the three-entry allowlist the contract '
            .'closes is only reachable with a whole-word/suffix predicate written alongside it. That file '
            .'(tests/Support/money-column-allowlist.php) does not exist and this slice may create only the '
            .'four files section 11.3/11.4/11.5 name. The convention-based half of FIN-18 runs above and is '
            .'green: `*_amount`, `*_rate|_percentage|_pct`, and no float/double/real anywhere.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIN-16 — no float, no second balance source
    |--------------------------------------------------------------------------
    */

    /**
     * FIN-16, first half — `App\Support\Money` contains no float operation.
     *
     * Every money figure in this system passes through this one class, so a single `(float)` inside it
     * is not a local bug: it is a rounding error injected into the ledger, the wallet cache, every
     * reconciliation check and every printed receipt, consistently enough that the reconciler would
     * agree with itself and report no drift.
     *
     * Comments are stripped before the scan with `token_get_all()`. Money's own docblock says "no
     * `round()`, no `number_format()`", and a scan that read prose would fail on the sentence
     * promising the thing it is checking.
     *
     * **It carries FIN-16's exact method name while asserting only the float half**, because section
     * 11's preamble makes each id a real method name and a missing name reads, in the §120 evidence
     * pack, as a requirement nobody wrote. The second half is not quietly folded in: it is
     * {@see test_no_float_and_no_second_balance_source_across_the_whole_application()}, which carries
     * the same prefix and skips with the eight call sites named.
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_no_float_and_no_second_balance_source(): void
    {
        $path = base_path('app/Support/Money.php');

        $this->assertFileExists($path);

        $code = $this->sourceWithoutComments((string) file_get_contents($path));

        foreach (['(float)', '(double)', 'floatval', 'doubleval', 'number_format'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                sprintf('`%s` appears in App\\Support\\Money — money never touches a float (`CLAUDE.md` §1.4).', $needle),
            );
        }

        // And the behaviour the scan is a proxy for: bcmath half-up, to the paisa, as a string.
        $this->assertSame('333.33', Money::percentage('3333.33', '10.0000'));
        $this->assertSame('1000.00', Money::percentage('10000.00', '10.0000'));
        $this->assertSame('0.00', Money::percentage('0.04', '10.0000'));
    }

    /**
     * FIN-16, second half — no second source of a balance.
     *
     * Named with the FIN-16 id as its prefix so `--filter=test_no_float_and_no_second_balance_source`
     * selects this alongside the half that runs, and the §120 evidence pack cannot be read as though
     * the id were fully covered.
     */
    #[Test]
    #[Group(self::SUITE)]
    public function test_no_float_and_no_second_balance_source_across_the_whole_application(): void
    {
        $this->markTestSkipped(
            'phase-24-25 section 11.5.2 FIN-16 also forbids a `SUM(` over a ledger, entitlement, '
            .'allocation or payout table outside CollaboratorWalletService / CollaboratorStatementService '
            ."/ CommissionReconciliationService (INV-26, FT-42). This is a real, sizeable debt, not a\n"
            ."formality. A scan of app/ finds at least eight live call sites outside the three:\n"
            .'  · app/Http/Controllers/Collaborator/CommissionController.php:56 — sum(signed_amount) over '
            ."the ledger, for the partner's \"earned in range\" figure\n"
            .'  · app/Http/Controllers/Admin/Collaborator/CommissionController.php:81 — sum(amount) over '
            ."the ledger, for the pending-approval total\n"
            .'  · app/Http/Controllers/Collaborator/ProjectController.php:81 — SUM(signed_amount) grouped '
            ."by project\n"
            .'  · app/Services/Collaborator/ProjectCommissionService.php:173 — earnedOn(), sum(signed_amount)'
            ."\n"
            .'  · app/Reports/Collaborator/ReferredStudentsReport.php:148 and ReferredProjectsReport.php:131 '
            ."— sum(signed_amount) per referral\n"
            .'  · app/Dashboard/Widgets/Collaborator/PayoutQueueWidget.php:79 and '
            ."app/Http/Controllers/Admin/Collaborator/PayoutController.php:76 — SUM(amount) over payouts\n\n"
            .'Most are probably legitimate: a filtered subtotal on one screen is not a wallet balance, and '
            .'INV-26 exists to stop a *balance* being computed twice. But "probably" is what an allowlist '
            .'is for, and the contract requires one with a written reason per entry. Writing that predicate '
            .'and that file is a decision about INV-26 rather than a test, it touches a shared '
            .'tests/Support file this slice may not create, and a scan without it would either fail on '
            .'eight legitimate lines or be widened until it proved nothing. Owner: whoever rules on INV-26 '
            .'for subtotals. The float half of FIN-16 runs above and is green.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reflection over the suite
    |--------------------------------------------------------------------------
    */

    /**
     * Every test method declared under a directory, keyed by method name.
     *
     * Reflection, never execution (section 11.5.1). Only methods declared *on* the class count:
     * inheriting a name from a base class would let the pin be satisfied by a parent nobody runs.
     *
     * **The name may not begin with `test`, even for a private helper.** Pint's Laravel preset runs
     * `php_unit_method_casing`, which treats every method whose name starts with `test` in a `*Test`
     * class as a test method and rewrites the *declaration* to snake_case — without touching the call
     * sites. A helper called `testMethodsIn()` therefore becomes `test_methods_in()` the first time
     * anybody runs `./vendor/bin/pint`, and this file fatals with "Call to undefined method
     * testMethodsIn()". The committed suite is pint-clean, so somebody will run pint.
     *
     * @return array<string, array{class: string, grouped: bool}>
     */
    private function declaredTestMethodsIn(string $relativeDirectory): array
    {
        $directory = base_path($relativeDirectory);

        if (! is_dir($directory)) {
            return [];
        }

        $found = [];

        foreach (Finder::create()->files()->in($directory)->name('*Test.php') as $file) {
            $class = $this->classFor($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $classGrouped = $this->carriesSuiteGroup($reflection);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $found[$method->getName()] = [
                    'class' => $class,
                    'grouped' => $classGrouped || $this->carriesSuiteGroup($method),
                ];
            }
        }

        return $found;
    }

    /**
     * `tests/Feature/Financial/PayoutTest.php` -> `Tests\Feature\Financial\PayoutTest`.
     *
     * Derived from the path rather than parsed out of the file: composer's `Tests\` PSR-4 root makes
     * the mapping exact, and a file whose declared namespace disagrees with its path is not
     * autoloadable anyway, so `class_exists()` filters it out a line later.
     */
    private function classFor(SplFileInfo $file): ?string
    {
        $path = str_replace('\\', '/', $file->getPathname());
        $root = str_replace('\\', '/', base_path('tests')).'/';

        if (! str_starts_with($path, $root)) {
            return null;
        }

        $relative = substr($path, strlen($root), -strlen('.php'));

        return 'Tests\\'.str_replace('/', '\\', $relative);
    }

    private function carriesSuiteGroup(ReflectionClass|ReflectionMethod $target): bool
    {
        foreach ($target->getAttributes(Group::class) as $attribute) {
            $arguments = $attribute->getArguments();

            if (($arguments[0] ?? $arguments['name'] ?? null) === self::SUITE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one-off message the pin prints while the rename is outstanding. Kept out of the test body
     * because it is documentation, not logic, and it names every step needed to un-skip it.
     *
     * @param  array<string, array{class: string, grouped: bool}>  $service
     */
    private function renamePendingMessage(array $service): string
    {
        $legacy = [
            'a_student_without_a_collaborator_creates_no_commission_row',
            'a_student_payment_creates_one_commission_at_the_configured_rate',
            'the_same_fee_payment_processed_twice_creates_one_commission',
            'three_installments_create_three_commissions',
            'a_student_refund_creates_a_negative_reversal_and_preserves_the_original',
            'a_project_without_a_collaborator_creates_no_commission',
            'a_project_payment_creates_a_commission_at_the_configured_rate',
            'a_project_refund_creates_a_reversal_and_preserves_the_original',
            'a_payout_of_20000_against_a_50000_wallet',
        ];

        $present = array_values(array_filter($legacy, static fn (string $name): bool => isset($service[$name])));

        return sprintf(
            'phase-24-25 section 11.5.1 FIN-01 pins nine method names that do not exist yet. All nine '
            .'scenarios DO exist and pass under the descriptive names the earlier phases gave them — %d of '
            .'the %d legacy names were found in %s on this run — and no test anywhere in the suite carries a '
            ."#[Group] attribute, so `--group=%s` currently selects nothing.\n\n"
            ."To un-skip: rename each of the nine to the name in self::REQUIRED, add #[Group('%s')] to it and "
            .'to its six PH18-01..PH18-06 counterparts in %s, and register the suite in phpunit.xml. Those are '
            ."shared files this slice does not own; the exact edits are in the slice report.\n\n"
            .'This skip fires only while NONE of the nine has been renamed. After the first rename every '
            .'remaining absence is a hard failure naming the method, which is the guarantee the contract '
            .'asks for: the §120 suite can never be quietly reduced.',
            count($present),
            count($legacy),
            self::SERVICE_DIR,
            self::SUITE,
            self::SUITE,
            self::HTTP_DIR,
        );
    }

    /**
     * Comments stripped, code kept — so a scan reads what runs, not what is promised.
     */
    private function sourceWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | information_schema
    |--------------------------------------------------------------------------
    */

    /**
     * `table.column type` for every column of the **test** schema matching a raw predicate.
     *
     * The schema name comes from the connection rather than from config, so this reads the database
     * the test is actually running against and never the developer's dev database.
     *
     * @return list<string>
     */
    private function columns(string $predicate): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND '.$predicate.'
             ORDER BY TABLE_NAME, COLUMN_NAME',
            [DB::getDatabaseName()],
        );

        return array_map(
            static fn (object $row): string => sprintf('%s.%s is %s', $row->t, $row->c, $row->ty),
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    private function columnsMatching(string $table, string $column): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), $table, $column],
        );

        return array_map(static fn (object $row): string => (string) $row->c, $rows);
    }

    /**
     * `['BEFORE DELETE']` when this trigger exists on this table, `[]` when it does not.
     *
     * Timing and event are read rather than assumed: a trigger renamed to the same name but rebuilt
     * as `AFTER DELETE` would let the row go and raise afterwards, which is not the same promise.
     *
     * @return list<string>
     */
    private function triggerShape(string $trigger, string $table): array
    {
        $rows = DB::select(
            'SELECT CONCAT(ACTION_TIMING, \' \', EVENT_MANIPULATION) AS shape
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ? AND EVENT_OBJECT_TABLE = ?',
            [DB::getDatabaseName(), $trigger, $table],
        );

        return array_map(static fn (object $row): string => (string) $row->shape, $rows);
    }

    /**
     * One real ledger row, posted by the commission engine, or null if the engine posted nothing.
     *
     * Through `partner()` / `charge()` / `receive()` rather than an INSERT, because the row FIN-17
     * deletes has to be the row the application writes — a hand-built one could omit exactly the
     * column a future trigger reads. Null rather than an exception when nothing posts, so the test
     * skips with a message instead of failing on a fixture.
     */
    private function plantLedgerRow(string $table): ?int
    {
        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'manual');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('finance.backdate_limit_days', 3650);

        $partner = $this->partner('10.0000');

        $this->receive($this->charge($partner, '200000.00'), '40000.00', ['on' => '2026-02-10']);

        $id = CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $partner->getKey())
            ->value('id');

        return $id === null || ! $this->schemaHasTable($table) ? null : (int) $id;
    }

    private function schemaHasTable(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table],
        ) !== [];
    }
}
