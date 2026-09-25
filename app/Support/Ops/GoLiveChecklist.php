<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Enums\IntegrityCheckSuite;

/**
 * The fifty-two go-live items as data (phase-24-25 section 6.13, section 8.5).
 *
 * **A checklist that lives in a command is a checklist the screen has to guess at.** Section 6.13
 * is read by three things — `golive:check`, the `admin.system-health.go-live` tab and whoever
 * updates `docs/GO-LIVE.md` — and the failure this class exists to prevent is the ordinary one: the
 * command grows a row, the screen does not, and the tab renders forty-nine green rows while the
 * console exits 2. One declaration, three readers, no way for them to disagree about what the
 * checklist *is*. What each reader decides is how to *render* it.
 *
 * **Every row states how it is checked, and "not stated" is not an option.** A row carries exactly
 * one of four kinds. {@see KIND_COMMAND} names an artisan command and its arguments; the row passes
 * when that command exits 0. {@see KIND_INSPECT} names a method the evaluator implements against
 * configuration, php.ini, the settings store or a table. {@see KIND_TEST} names an acceptance test
 * this command deliberately does not run, and is satisfied only by a recorded green
 * `integrity_check_runs` verdict for its suite. {@see KIND_MANUAL} is a sentence somebody signs.
 *
 * **The reason `KIND_TEST` exists at all is the sentence the phase is most serious about: a
 * checklist that reports green for an item nothing verified is the single most dangerous artefact
 * in this phase.** Eleven rows of section 6.13 are proved by a test in `tests/Feature/*`. A command
 * cannot honestly run PHPUnit against itself — and on this installation it must not try, because
 * the test connection is a separate database nobody may migrate from a gate (D157). So those rows
 * do not guess. They look for the verdict the nightly `integrity:verify` recorded, and when there
 * is none they report "not yet checkable" and name the test. An unproved blocker stays open, which
 * is the only reading of section 6.13 that makes the exit code worth having.
 *
 * **A blocker is fixed, never documented away** (invariant HD-1). The `blocks` flag here is the
 * contract's, not a preference: seven rows are non-blocking (GL-05, GL-10, GL-23, GL-39, GL-43,
 * GL-45, GL-46) and the other forty-five are not. Loosening one to make the command green is a
 * review failure, not a configuration change — which is why the flag lives in a data table a
 * reviewer can diff against `docs/GO-LIVE.md` rather than inside an `if`.
 *
 * The evaluator is `App\Console\Commands\Ops\GoLiveCheck`, named here in prose rather than imported:
 * a support class that imports a command has the dependency the wrong way round, and this one owes
 * the command nothing.
 *
 * @see docs/GO-LIVE.md  the same fifty-two rows, in prose, for the operator
 */
final class GoLiveChecklist
{
    /**
     * The row passes when `command` + `arguments` exits 0, and only on 0.
     *
     * **Exit 1 is not a pass**, even where the command itself means "warnings only" by it. Section
     * 6.13 says `security:audit` *exits 0*, and a gate that accepts a warning is a gate whose
     * warnings nobody comes back to. The exit code and an excerpt of the output are printed either
     * way, so the difference between a warning and a finding is visible to the person reading it.
     */
    public const KIND_COMMAND = 'command';

    /** The row is decided by an inspector method on the evaluator — config, php.ini, a setting, a table. */
    public const KIND_INSPECT = 'inspect';

    /**
     * The row's proof is a named acceptance test, and this class will not pretend otherwise.
     *
     * Satisfied only by a recorded `integrity_check_runs` verdict for {@see self::suiteFor()}'s
     * suite that is green and recent. No run recorded → "not yet checkable", naming the test.
     */
    public const KIND_TEST = 'test';

    /** No machine half at all: a sentence with a name and a date against it. */
    public const KIND_MANUAL = 'manual';

    /**
     * The groups, in the order section 8.5 renders them.
     *
     * Order is content here, not styling. Environment is first because nothing below it can be
     * trusted while `APP_DEBUG=true`, and Sign-off is last because it is the row that outlives the
     * project.
     *
     * @var list<string>
     */
    public const GROUPS = [
        'Environment',
        'Security',
        'Data',
        'Financial',
        'Performance',
        'Backup',
        'Operations',
        'UX',
        'Content',
        'Sign-off',
    ];

    /**
     * Where a manual tick is recorded, and how the evaluator recognises one.
     *
     * Section 8.5 has the screen write the tick into the activity log with who ticked it and when.
     * There is no `golive_ticks` table in section 2 and there must not be one: a tick is an audit
     * fact about a person, and `activity_log` is already the append-only store for exactly that.
     *
     * The log name is the owning module's slug, as every other `activity()` call in this codebase
     * does it, so the existing audit-trail filters find these rows without a special case.
     */
    public const LOG_NAME = 'system_health';

    /** `properties->item` carries the GL id; the causer is the person. */
    public const EVENT_TICKED = 'golive.ticked';

    /** A waiver, which needs `system_health.view_logs` and a reason of at least twenty characters. */
    public const EVENT_WAIVED = 'golive.waived';

    /**
     * How stale a recorded suite verdict may be and still stand for a `KIND_TEST` row.
     *
     * Thirty-six hours, matching the financial-proof window the health screen judges by: the
     * nightly proofs run at 02:15, so a run from last night is evidence and a run from last week is
     * a claim about code that has since been deployed over. **A proof with no expiry is not a
     * proof, it is a memory.**
     */
    public const SUITE_VERDICT_MAX_HOURS = 36;

    /**
     * Every row of section 6.13, keyed by id.
     *
     * Each value is an array with these keys, all of them always present so no reader has to test
     * for one:
     *
     *   id          string            GL-01 .. GL-52
     *   group       string            one of self::GROUPS
     *   title       string            the item, as section 6.13 words it
     *   blocks      bool              does an open row stop the go-live
     *   kind        string            one of the four KIND_* constants
     *   manual      bool              does this row ALSO need a human tick (true for all KIND_MANUAL)
     *   command     ?string           KIND_COMMAND: the artisan command to run
     *   arguments   array             KIND_COMMAND: its arguments, in Artisan::call() form
     *   inspector   ?string           KIND_INSPECT: the evaluator method that decides it
     *   test        ?string           the acceptance test that is the real proof, where there is one
     *   suite       ?string           KIND_TEST: the IntegrityCheckSuite whose recorded verdict stands in
     *   evidence    string            what to look at, for the screen's "evidence" column
     *   note        ?string           the one thing a reader would otherwise get wrong
     *
     * @return array<string, array<string, mixed>>
     */
    public static function items(): array
    {
        $rows = [];

        foreach ([
            ...self::environment(),
            ...self::security(),
            ...self::data(),
            ...self::financial(),
            ...self::performance(),
            ...self::backup(),
            ...self::operations(),
            ...self::ux(),
            ...self::content(),
            ...self::signOff(),
        ] as $row) {
            $rows[$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::items());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        return self::items()[$id] ?? null;
    }

    /**
     * The rows grouped for the screen, in `self::GROUPS` order.
     *
     * A group with no rows is not returned: an empty accordion is a question the reader has to
     * answer for themselves.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byGroup(): array
    {
        $grouped = [];

        foreach (self::GROUPS as $group) {
            $rows = array_values(array_filter(
                self::items(),
                static fn (array $row): bool => $row['group'] === $group,
            ));

            if ($rows !== []) {
                $grouped[$group] = $rows;
            }
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public static function blockers(): array
    {
        return array_keys(array_filter(self::items(), static fn (array $row): bool => (bool) $row['blocks']));
    }

    /**
     * The summary table of `docs/GO-LIVE.md`, computed rather than typed.
     *
     * The document states these six figures. Computing them here means a row added to this class
     * and not to the document is a visible arithmetic disagreement rather than a silent one — which
     * is the only way two artefacts written from one contract section stay in step.
     *
     * @return array{items: int, blockers: int, non_blockers: int, machine: int, machine_and_manual: int, manual_only: int}
     */
    public static function counts(): array
    {
        $items = self::items();

        $machineHalf = array_filter($items, static fn (array $row): bool => $row['kind'] !== self::KIND_MANUAL);

        return [
            'items' => count($items),
            'blockers' => count(array_filter($items, static fn (array $row): bool => (bool) $row['blocks'])),
            'non_blockers' => count(array_filter($items, static fn (array $row): bool => ! $row['blocks'])),
            'machine' => count(array_filter($machineHalf, static fn (array $row): bool => ! $row['manual'])),
            'machine_and_manual' => count(array_filter($machineHalf, static fn (array $row): bool => (bool) $row['manual'])),
            'manual_only' => count($items) - count($machineHalf),
        ];
    }

    /**
     * The suite a `KIND_TEST` row's recorded verdict must belong to.
     *
     * Returns null for every other kind, so a caller cannot accidentally accept a suite verdict as
     * evidence for a row that has its own command.
     */
    public static function suiteFor(string $id): ?IntegrityCheckSuite
    {
        $row = self::find($id);

        if ($row === null || $row['kind'] !== self::KIND_TEST || $row['suite'] === null) {
            return null;
        }

        return IntegrityCheckSuite::tryFrom((string) $row['suite']);
    }

    /*
    |--------------------------------------------------------------------------
    | The rows, group by group
    |--------------------------------------------------------------------------
    |
    | Wording follows section 6.13 closely on purpose: an operator reading the console output and an
    | operator reading docs/GO-LIVE.md must be able to see they are looking at the same row.
    |
    */

    /**
     * @return list<array<string, mixed>>
     */
    private static function environment(): array
    {
        return [
            self::inspect(
                id: 'GL-01',
                group: 'Environment',
                title: 'APP_ENV=production, APP_DEBUG=false, APP_KEY set and not the example value',
                inspector: 'environment',
                evidence: "config('app.env'), config('app.debug'), config('app.key')",
                test: 'DEP-10 test_production_configuration_sanity',
                note: 'Nothing below this row can be trusted while it is open: a system running with '
                    .'debug on is not the system that was tested.',
            ),
            self::inspect(
                id: 'GL-02',
                group: 'Environment',
                title: 'APP_URL is the real HTTPS host and matches the request host',
                inspector: 'appUrl',
                evidence: "config('app.url')",
                test: 'DEP-10 test_production_configuration_sanity',
            ),
            self::inspect(
                id: 'GL-03',
                group: 'Environment',
                title: 'Config, routes, views and events cached',
                inspector: 'caches',
                evidence: 'bootstrap/cache — the four artefacts `php artisan about` reports',
                note: 'Checked as the four files rather than by parsing `about`, because the question '
                    .'is whether the cache exists, and that is a fact on disk.',
            ),
            self::test(
                id: 'GL-04',
                group: 'Environment',
                title: 'No env() call outside config/',
                test: 'DEP-04 test_no_env_call_outside_config',
                suite: IntegrityCheckSuite::Security,
                evidence: 'the static scan in the security suite',
                note: 'The subtle one. Once configuration is cached an env() call outside config/ '
                    .'returns null — silently, in production only, on the one path nobody exercised '
                    .'locally.',
            ),
            self::inspect(
                id: 'GL-05',
                group: 'Environment',
                title: 'ops.app_version stamped, and matching the deployed release',
                inspector: 'appVersion',
                evidence: "setting('ops.app_version')",
                blocks: false,
                note: 'Non-blocking, and still not optional: an unstamped version means a restored '
                    .'archive cannot say which release made it.',
            ),
            self::inspect(
                id: 'GL-06',
                group: 'Environment',
                title: 'PHP extensions present; opcache on with validate_timestamps=0; max_input_vars >= 5000',
                inspector: 'phpRuntime',
                evidence: 'php -m, ini_get() — section 6.9.7',
                test: 'SEC-19 posts a full permission matrix',
                note: 'max_input_vars is not cosmetic. Below 5000 PHP truncates the role editor\'s '
                    .'permission matrix and the role saves with permissions missing, with no error '
                    .'anywhere.',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function security(): array
    {
        return [
            self::inspect(
                id: 'GL-07',
                group: 'Security',
                title: 'HTTPS serves, HTTP 301s, no mixed content on home, admin and a print view',
                inspector: 'httpsConfigured',
                evidence: "config('app.url') scheme + setting('security.force_https'); then the browser console on the three pages",
                manual: true,
                note: 'The machine half is the application\'s half only. Whether Apache redirects and '
                    .'whether those three pages are free of mixed content is a request somebody makes '
                    .'by hand, against the real web server.',
            ),
            self::inspect(
                id: 'GL-08',
                group: 'Security',
                title: 'security.force_https on; HSTS on after GL-07 is green',
                inspector: 'hsts',
                evidence: "setting('security.force_https'), setting('security.hsts_enabled')",
                note: 'Order matters and the row checks it: HSTS before HTTPS actually serves locks '
                    .'every browser that saw the header out of a site that cannot answer on TLS.',
            ),
            self::test(
                id: 'GL-09',
                group: 'Security',
                title: 'Every security header present on an authenticated and a public response',
                test: 'SEC-06 test_security_headers_present',
                suite: IntegrityCheckSuite::Security,
                evidence: 'the runtime header sweep in the security suite',
            ),
            self::inspect(
                id: 'GL-10',
                group: 'Security',
                title: 'CSP enforcing (not report-only) and the report log clean for 48 hours',
                inspector: 'cspEnforcing',
                evidence: "setting('security.csp_report_only') === false; then the CSP report log",
                blocks: false,
                manual: true,
                note: 'Non-blocking because a report-only CSP still reports. Leave it report-only '
                    .'until the log is clean for two days: flipping it early breaks working screens '
                    .'for real users, which is worse than a header that only observes.',
            ),
            self::command(
                id: 'GL-11',
                group: 'Security',
                title: 'php artisan security:audit exits 0',
                command: 'security:audit',
                arguments: ['--quiet-run' => true],
                evidence: 'security:audit, and the integrity_check_runs row it writes',
            ),
            self::command(
                id: 'GL-12',
                group: 'Security',
                title: 'audit:manifest --check exits 0 — every route, upload and index accounted for',
                command: 'audit:manifest',
                arguments: ['--check' => true],
                evidence: 'audit:manifest --check',
                note: 'Drift in both directions. An orphaned row asserts a guarantee about a screen '
                    .'nobody can open, which reads as coverage.',
            ),
            self::test(
                id: 'GL-13',
                group: 'Security',
                title: 'Exactly one Super Admin; no account on a seeded or demo password; every staff '
                    .'account\'s must_change_password cleared by its owner',
                test: 'DEP-06 test_the_first_super_admin_is_created_securely',
                suite: IntegrityCheckSuite::Security,
                evidence: 'the seeded-password sweep in the security suite',
            ),
            self::inspect(
                id: 'GL-14',
                group: 'Security',
                title: '/register returns 404 (D15); password reset and verification throttled',
                inspector: 'registrationClosed',
                evidence: 'the live route list and the named limiters of section 6.3.1',
                note: 'Asked of the router rather than of a route file, because a route registered '
                    .'from a package is still a route.',
            ),
            self::test(
                id: 'GL-15',
                group: 'Security',
                title: '.env not web-reachable and not world-readable; storage/logs, vendor/, '
                    .'composer.json, /docs and /.git not web-reachable',
                test: 'DEP-09 test_nothing_outside_public_is_web_reachable',
                suite: IntegrityCheckSuite::Security,
                evidence: 'the reachability sweep in the security suite',
            ),
            self::command(
                id: 'GL-16',
                group: 'Security',
                title: 'Only storage/ and bootstrap/cache/ writable by the web user',
                command: 'security:audit',
                arguments: ['--quiet-run' => true],
                evidence: 'the writable-directory sweep inside security:audit',
                note: 'Shares GL-11\'s run: the directory sweep is one of security:audit\'s checks, so '
                    .'a finding here is a finding there. The row is kept separate because an operator '
                    .'fixing file permissions needs to see the item, not to read an audit summary.',
            ),
            self::test(
                id: 'GL-17',
                group: 'Security',
                title: 'An uploaded .php under the storage alias is served as text or denied, never executed',
                test: 'DEP-08 test_upload_directories_cannot_execute_code',
                suite: IntegrityCheckSuite::Uploads,
                evidence: 'DEP-08, plus one manual request against the real Apache',
                manual: true,
                note: 'The manual half is not ceremony. DEP-08 proves the application refuses the '
                    .'upload and that the .htaccess directives are present; only a real request to '
                    .'the real web server proves Apache is honouring them.',
            ),
            self::command(
                id: 'GL-18',
                group: 'Security',
                title: 'composer audit and npm audit --omit=dev: no high or critical advisory',
                command: 'security:audit',
                arguments: ['--quiet-run' => true],
                evidence: 'the dependency suite inside security:audit',
                note: 'Shares GL-11\'s run, same reason as GL-16.',
            ),
            self::test(
                id: 'GL-19',
                group: 'Security',
                title: 'Three least-privilege MySQL users; root has a password; anonymous users '
                    .'dropped; the app user cannot migrate',
                test: 'DEP-07 test_database_privileges_are_separated',
                suite: IntegrityCheckSuite::Security,
                evidence: 'DEP-07 — `migrate --force` on the default connection must FAIL',
                note: 'D58 and D168. The test asserts the control is ON, not that the code path '
                    .'exists: both split connections fall back to the app credentials, so on XAMPP '
                    .'the separation passes by landing on root and proves nothing.',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function data(): array
    {
        return [
            self::command(
                id: 'GL-20',
                group: 'Data',
                title: 'migrate:status clean; integrity:verify --suite=constraints exits 0 — every '
                    .'CHECK, generated column, unique guard and delete trigger present',
                command: 'integrity:verify',
                arguments: ['--suite' => 'constraints'],
                evidence: 'integrity:verify --suite=constraints',
                note: 'The nine BEFORE DELETE triggers make an append-only table append-only even '
                    .'against a hand-typed DELETE (D19, D16), and D70 is why the CHECKs are ensured '
                    .'on every migration run rather than only on CREATE.',
            ),
            self::command(
                id: 'GL-21',
                group: 'Data',
                title: 'Every money column decimal(15,2), every *_rate / *_percentage decimal(8,4), no '
                    .'float or double anywhere, and FIN-18\'s allowlist still holds exactly its three entries',
                command: 'integrity:verify',
                arguments: ['--suite' => 'schema'],
                evidence: 'integrity:verify --suite=schema; FIN-18 test_money_columns_are_decimal_everywhere',
                test: 'FIN-18 test_money_columns_are_decimal_everywhere',
                note: 'The allowlist is closed at exactly three entries — progress, rating and '
                    .'*_marks. A fourth entry is a review failure, not a configuration change.',
            ),
            /*
            | **This row asks two questions and only one of them is machine-answerable**, which is
            | why it is `manual` as well as inspected.
            |
            | "Demo data absent from production" can be checked, by looking for the seeder's own
            | marker rows. "demo:seed refuses to run" cannot be, without running `demo:seed` — and
            | a gate that trips the guard it is testing is not a gate.
            |
            | Before this split the inspector had a FAIL branch and a NOT_CHECKABLE branch and no
            | PASS branch at all. With no `manual` tick path either, `open` was true on every run
            | for ever, so `golive:check` could never exit 0 — and exit 0 is what install step 19
            | and deploy step 16 both wait on. The gate was unreachable on a perfectly healthy
            | system, which is the failure mode where somebody eventually stops running the gate.
            |
            | Same treatment GL-17 uses for its Apache half: the machine answers what it can, a
            | person ticks the rest against a deployment test.
            */
            self::inspect(
                id: 'GL-22',
                group: 'Data',
                title: 'Demo data absent from production; demo:seed refuses to run',
                inspector: 'demoData',
                evidence: 'no DemoSeeder marker rows in production; demo:seed refusal witnessed by '
                    .'the operator',
                manual: true,
                note: 'The inspector answers the first half only. The refusal cannot be proved '
                    .'without running demo:seed against production, so it is ticked by whoever '
                    .'witnessed it on the deployment test.',
            ),
            self::inspect(
                id: 'GL-23',
                group: 'Data',
                title: 'One default branch exists; institute.default_branch_id set (D11)',
                inspector: 'defaultBranch',
                evidence: "the branches table and setting('institute.default_branch_id')",
                blocks: false,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function financial(): array
    {
        return [
            self::test(
                id: 'GL-24',
                group: 'Financial',
                title: 'The nine section-120 tests green, by name',
                test: 'php artisan test --group=financial-120',
                suite: IntegrityCheckSuite::Constraints,
                evidence: 'the financial-120 group, and the nightly constraints verdict',
            ),
            /*
            | **A suite row, not a command row, because the command's exit code cannot carry this
            | verdict.** `collaborators:reconcile-wallets` ends
            | `return $failed === [] ? SUCCESS : FAILURE`, and `$failed` collects only reports whose
            | `structural()` is non-empty. A pure cache drift lands in `$drifted` and never reaches
            | the exit code — so as a command row this printed `pass` while a collaborator's wallet
            | disagreed with its ledger, on a BLOCKING financial row whose own note says drift is
            | never rounding. A checklist that reports green for something nothing verified is the
            | most dangerous artefact in this phase, and this was one.
            |
            | `IntegrityCheckStatus::blocksGoLive()` already treats a *warning* on a financial suite
            | as blocking, and drift is exactly a warning — so the verdict comes from the recorded
            | `integrity_check_runs` row instead. The command is still the evidence; it is no longer
            | the judge.
            */
            self::test(
                id: 'GL-25',
                group: 'Financial',
                title: 'collaborators:reconcile-wallets over every collaborator: zero drift, zero '
                    .'structural failure, the closed identity holds',
                test: 'the nightly wallet reconciliation (integrity:verify --suite=wallet)',
                suite: IntegrityCheckSuite::Wallet,
                evidence: 'collaborators:reconcile-wallets (read-only) and the rows it writes',
                note: 'Never --repair as a go-live step. The closed identity is '
                    .'lifetime = pending + available + reserved + paid, and drift is never '
                    .'"rounding": a wrong figure is corrected by a reversing entry that references '
                    .'the original (CLAUDE.md section 1 rule 3). Judged by the suite verdict rather '
                    .'than the command exit code, which reports only structural failure.',
            ),
            self::command(
                id: 'GL-26',
                group: 'Financial',
                title: 'fees:verify-plan-integrity zero drift',
                command: 'fees:verify-plan-integrity',
                arguments: [],
                evidence: 'fees:verify-plan-integrity',
            ),
            self::test(
                id: 'GL-27',
                group: 'Financial',
                title: 'The property suite green on the committed seeds and one fresh random seed',
                test: 'FIN-15 test_wallet_always_equals_the_ledger',
                suite: IntegrityCheckSuite::Wallet,
                evidence: 'FIN-15, and the nightly wallet reconciliation verdict',
            ),
            self::manual(
                id: 'GL-28',
                group: 'Financial',
                title: 'Commission settings confirmed with the client in writing: base, approval mode, '
                    .'minimum payout, payout request on/off, fixed release mode',
                evidence: 'recorded in DEVELOPMENT_LOG.md section 9',
                note: 'Manual and blocking because no test can tell you whether the client agreed to '
                    .'the commission base. Getting it wrong is not a bug that surfaces in a log — it '
                    .'is money paid to the wrong person for months.',
            ),
            self::inspect(
                id: 'GL-29',
                group: 'Financial',
                title: 'commissions:sweep, commissions:release-held, collaborators:reconcile-wallets '
                    .'and financial:verify-constraints all listed in schedule:list',
                inspector: 'financialSchedule',
                evidence: 'the registered schedule — the same list schedule:list prints',
                note: 'Matters more than it looks. These four are what make invariant HD-10 true: '
                    .'the proof runs every night, so a regression introduced in month seven is found '
                    .'that night rather than by a client.',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function performance(): array
    {
        return [
            self::command(
                id: 'GL-30',
                group: 'Performance',
                title: 'perf:budget --all exits 0 on the volume fixture',
                command: 'perf:budget',
                arguments: ['--all' => true],
                evidence: 'perf:budget --all',
                note: 'The volume fixture is the only place you will meet five thousand rows before '
                    .'the client does.',
            ),
            self::inspect(
                id: 'GL-31',
                group: 'Performance',
                title: 'No lazy-loading violation in the suite; none logged in the last 24 hours of staging',
                inspector: 'lazyLoadViolations',
                evidence: 'storage/logs — the "Lazy loading violation" lines of the last 24 hours',
                test: 'the suite itself, via Model::preventLazyLoading',
                note: 'Strict mode is off in production so a missed with() cannot 500 a paying '
                    .'client; the violation is logged instead. Which is why this row reads the log '
                    .'rather than trusting the absence of an exception.',
            ),
            self::test(
                id: 'GL-32',
                group: 'Performance',
                title: 'Assets built, hashed, inside budget, no source maps; build/ served with a '
                    .'one-year immutable cache',
                test: 'PRF-11 test_asset_budget',
                suite: IntegrityCheckSuite::Performance,
                evidence: 'PRF-11, plus PRODUCTION.md section 1',
            ),
            self::test(
                id: 'GL-33',
                group: 'Performance',
                title: 'Every index route paginates; exports stream',
                test: 'PRF-05 test_everything_paginates',
                suite: IntegrityCheckSuite::Performance,
                evidence: 'PRF-05',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function backup(): array
    {
        return [
            self::inspect(
                id: 'GL-34',
                group: 'Backup',
                title: 'Database backup scheduled and a completed row exists',
                inspector: 'databaseBackupPresent',
                evidence: 'backup_runs, and the backup.database_schedule setting',
                note: 'Both halves. A schedule with no completed row is a schedule nobody has '
                    .'watched run; a completed row with no schedule is one backup, once.',
            ),
            self::inspect(
                id: 'GL-35',
                group: 'Backup',
                title: 'Files backup scheduled and a completed row exists',
                inspector: 'filesBackupPresent',
                evidence: 'backup_runs, and the backup.files_schedule setting',
            ),
            self::inspect(
                id: 'GL-36',
                group: 'Backup',
                title: 'The latest database archive has verification_status = restore_ok',
                inspector: 'latestArchiveRestoreOk',
                evidence: 'backup_runs.verification_status; backup:verify --latest --deep',
                test: 'DEP-14 test_deep_verification_restores_and_reconciles',
                note: 'Invariant HD-6 in one row: a backup is not a backup until it has been '
                    .'restored. A checksum proves the file is intact, not that it contains a working '
                    .'system.',
            ),
            self::inspect(
                id: 'GL-37',
                group: 'Backup',
                title: 'An offsite copy exists (offsite_copied_at) while '
                    .'backup.offsite_required_for_go_live is true',
                inspector: 'offsiteCopy',
                evidence: "backup_runs.offsite_copied_at; setting('backup.offsite_required_for_go_live')",
            ),
            self::manual(
                id: 'GL-38',
                group: 'Backup',
                title: 'A full restore rehearsal into the scratch database performed by the client\'s '
                    .'operator, timed, and the recovery time recorded',
                evidence: 'recorded in DEVELOPMENT_LOG.md, with the elapsed time',
                note: 'Manual and blocking for a reason that has nothing to do with software: on the '
                    .'day it is needed the person restoring will be the client\'s operator, under '
                    .'pressure, possibly at night. The elapsed time is the recovery time objective, '
                    .'and the only honest answer to "how long would we be down?".',
            ),
            self::inspect(
                id: 'GL-39',
                group: 'Backup',
                title: 'Retention policy reviewed and the archive store has headroom below max_storage_gb',
                inspector: 'retentionHeadroom',
                evidence: 'backup:prune --dry-run; the disk free space',
                blocks: false,
                manual: true,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function operations(): array
    {
        return [
            self::inspect(
                id: 'GL-40',
                group: 'Operations',
                title: 'Queue worker running as a service, auto-restarting, heartbeat fresh',
                inspector: 'queueWorker',
                evidence: 'ops:health — the queue probe',
            ),
            self::inspect(
                id: 'GL-41',
                group: 'Operations',
                title: 'Scheduler running every minute, heartbeat fresh, every scheduled command listed',
                inspector: 'schedulerRunning',
                evidence: 'ops:health — the scheduler probe; the registered schedule',
            ),
            self::inspect(
                id: 'GL-42',
                group: 'Operations',
                title: 'failed_jobs empty, or every row read and explained',
                inspector: 'failedJobs',
                evidence: 'queue:failed',
                note: '"Read and explained", not "cleared". queue:flush on a table nobody read '
                    .'discards work — including commission jobs, which is lost money until the '
                    .'sweeper re-queues it.',
            ),
            self::inspect(
                id: 'GL-43',
                group: 'Operations',
                title: '/health answers with the token and 404s without it; the client\'s monitoring '
                    .'points at it',
                inspector: 'healthEndpoint',
                evidence: 'ops:health --json; the monitoring configuration',
                blocks: false,
                manual: true,
                note: 'The machine half proves the endpoint is enabled and holds a token. Whether '
                    .'anybody is actually polling it is the manual half, and it is the half that '
                    .'matters.',
            ),
            self::manual(
                id: 'GL-44',
                group: 'Operations',
                title: 'Error notifications reach a real person: ops:digest delivered, '
                    .'backup.notify_emails verified, SMTP test mail received',
                evidence: 'the recipient confirms receipt',
                note: 'Blocking because unmonitored monitoring is worse than none: it produces the '
                    .'belief that somebody would be told. Send it with `ops:digest --force` and have '
                    .'the real person say they received it.',
            ),
            self::inspect(
                id: 'GL-45',
                group: 'Operations',
                title: 'Log rotation verified: application dailies pruned at ops.log_retention_days, '
                    .'Apache logs rotating',
                inspector: 'logRotation',
                evidence: 'storage/logs contents; the OS rotation job',
                blocks: false,
                manual: true,
                note: 'Laravel prunes its own dailies and ops:prune-logs makes the setting true for '
                    .'files the channel will never touch again. Apache\'s logs are the OS\'s job and '
                    .'the manual half.',
            ),
            self::manual(
                id: 'GL-46',
                group: 'Operations',
                title: 'Maintenance mode tested with the secret URL, and the 503 page is the branded one',
                evidence: 'php artisan down --secret=... then the secret URL',
                blocks: false,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ux(): array
    {
        return [
            self::manual(
                id: 'GL-47',
                group: 'UX',
                title: 'The responsive matrix ticked for every screen at five widths in both themes',
                evidence: 'the per-screen matrix of phase-24-25 section 8.7',
                note: 'Manual in full, and blocking, because a panel that is unusable on a phone is '
                    .'unusable for most of the people who will use it.',
            ),
            self::command(
                id: 'GL-48',
                group: 'UX',
                title: 'The accessibility matrix ticked; a11y:scan exits 0; contrast pairs pass in both themes',
                command: 'a11y:scan',
                arguments: [],
                evidence: 'a11y:scan, then the matrix of section 8.8',
                manual: true,
                note: 'Run the scan first so the manual pass is not spent on findings a command '
                    .'would have caught. A scan can prove a label exists; only a person can prove a '
                    .'screen is operable end to end without a mouse.',
            ),
            self::test(
                id: 'GL-49',
                group: 'UX',
                title: 'Every error page (403, 404, 419, 429, 500, 503) is branded and leaks nothing',
                test: 'SEC-37 test_error_pages_are_branded_and_silent',
                suite: IntegrityCheckSuite::Security,
                evidence: 'SEC-37',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function content(): array
    {
        return [
            self::manual(
                id: 'GL-50',
                group: 'Content',
                title: 'Company details, logo, favicon, currency PKR, timezone Asia/Karachi, date '
                    .'format, SEO defaults, sitemap and robots all set by the client',
                evidence: 'the settings screens; the rendered public site',
                note: '"By the client" is the operative phrase. Placeholder branding on a public site '
                    .'on launch day is the one failure every visitor sees, and it is the client who '
                    .'knows which logo is current.',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function signOff(): array
    {
        return [
            self::manual(
                id: 'GL-51',
                group: 'Sign-off',
                title: 'DEVELOPMENT_LOG.md section 5 shows phases 1-25 [x] with test notes; section 7 '
                    .'carries the dated results of every suite; section 8 lists every accepted risk; '
                    .'section 9 every client decision',
                evidence: 'the log itself',
            ),
            self::manual(
                id: 'GL-52',
                group: 'Sign-off',
                title: 'The client names the people who hold Super Admin, backups.restore and '
                    .'collaborator_payouts.approve, and confirms the separation of duties',
                evidence: 'recorded in DEVELOPMENT_LOG.md section 9',
                note: 'Last because it outlives the project. Three permissions can each cause '
                    .'irreversible harm, and the person who approves a payout must not be the person '
                    .'who requests it.',
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Row constructors
    |--------------------------------------------------------------------------
    |
    | Four named constructors rather than fifty-two literal arrays. The reason is not brevity: every
    | row then carries every key, so no reader has to write `$row['suite'] ?? null`, and a row that
    | forgot to say how it is checked is impossible to write rather than merely discouraged.
    |
    */

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function command(
        string $id,
        string $group,
        string $title,
        string $command,
        array $arguments,
        string $evidence,
        bool $blocks = true,
        bool $manual = false,
        ?string $test = null,
        ?string $note = null,
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'title' => $title,
            'blocks' => $blocks,
            'kind' => self::KIND_COMMAND,
            'manual' => $manual,
            'command' => $command,
            'arguments' => $arguments,
            'inspector' => null,
            'test' => $test,
            'suite' => null,
            'evidence' => $evidence,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function inspect(
        string $id,
        string $group,
        string $title,
        string $inspector,
        string $evidence,
        bool $blocks = true,
        bool $manual = false,
        ?string $test = null,
        ?string $note = null,
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'title' => $title,
            'blocks' => $blocks,
            'kind' => self::KIND_INSPECT,
            'manual' => $manual,
            'command' => null,
            'arguments' => [],
            'inspector' => $inspector,
            'test' => $test,
            'suite' => null,
            'evidence' => $evidence,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function test(
        string $id,
        string $group,
        string $title,
        string $test,
        IntegrityCheckSuite $suite,
        string $evidence,
        bool $blocks = true,
        bool $manual = false,
        ?string $note = null,
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'title' => $title,
            'blocks' => $blocks,
            'kind' => self::KIND_TEST,
            'manual' => $manual,
            'command' => null,
            'arguments' => [],
            'inspector' => null,
            'test' => $test,
            'suite' => $suite->value,
            'evidence' => $evidence,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function manual(
        string $id,
        string $group,
        string $title,
        string $evidence,
        bool $blocks = true,
        ?string $note = null,
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'title' => $title,
            'blocks' => $blocks,
            'kind' => self::KIND_MANUAL,
            // Always true: a manual row IS the human tick. Kept as an explicit key so a reader
            // filtering on `manual` gets all fifteen rows that need a signature, not just the seven
            // that also have a machine half.
            'manual' => true,
            'command' => null,
            'arguments' => [],
            'inspector' => null,
            'test' => null,
            'suite' => null,
            'evidence' => $evidence,
            'note' => $note,
        ];
    }
}
