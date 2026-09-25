<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Models\Ops\BackupRun;
use App\Models\Ops\IntegrityCheckRun;
use App\Services\Ops\BackupRetentionService;
use App\Services\Ops\SystemHealthService;
use App\Support\Ops\GoLiveChecklist;
use Illuminate\Console\Command;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * `golive:check` — the last gate before real clients, real students and real money (section 6.13).
 *
 * **Its value is that it disagrees with people.** Every other command here answers a technical
 * question; this one answers "are we allowed to launch", and the honest answer on most days is no.
 * The failure it exists to prevent is not a bug — it is the meeting where somebody says "I think
 * we're fine" and nobody can name which of fifty-two things has actually been proved.
 *
 * **Exit 2 while any blocker is open** (section 6.6). Exit 1 when only a non-blocker is open, 0 when
 * nothing is. Three states rather than two, so `composer golive` can fail a release while a missing
 * default branch merely prints.
 *
 * **It never reports green for something nothing verified — that artefact would be the most
 * dangerous thing this phase could ship.** So the four kinds of {@see GoLiveChecklist} are honoured
 * exactly:
 *
 *   - a `command` row runs its command and passes only on exit 0;
 *   - an `inspect` row is decided here, against configuration, php.ini, a setting or a table;
 *   - a `test` row is proved by an acceptance test this command will not run, and is satisfied only
 *     by a recorded `integrity_check_runs` verdict that is green and recent — with no such verdict
 *     it reports **"not yet checkable"** and names the test;
 *   - a `manual` row is a sentence somebody signs, and is closed only by a recorded tick.
 *
 * **Running PHPUnit from inside this command is deliberately not an option.** The test suite runs
 * against a separate database, and a gate that migrates it would be a gate that can destroy the
 * evidence it was asked to check (D157). `composer harden` runs the suite; this command reads what
 * the suite's nightly counterpart recorded. That is why a `test` row's verdict has an expiry: a
 * proof with no expiry is not a proof, it is a memory.
 *
 * **A blocker is fixed, never documented away** (HD-1). Nothing in this command can be passed a
 * flag that skips a row. The only legitimate way an open blocker becomes an acceptable state is a
 * written client decision in `DEVELOPMENT_LOG.md` section 9, and the only way it turns this output
 * green is the waiver of section 8.5 — which needs `system_health.view_logs`, a reason of at least
 * twenty characters, and leaves a row in the activity log with a name on it.
 *
 * @see docs/GO-LIVE.md  the same fifty-two rows in prose, and how a manual tick is written down
 */
final class GoLiveCheck extends Command
{
    /**
     * Section 6.6 states the signature, and it is short on purpose.
     *
     * No `--only=`, no `--skip=`. A gate with a filter is a gate somebody runs with the filter on.
     */
    protected $signature = 'golive:check {--json : Machine-readable output}';

    protected $description = 'Run every machine-checkable go-live item, print the manual ones, and exit 2 while any blocker is open.';

    /** Proved. */
    private const PASS = 'pass';

    /** Proved to be wrong. */
    private const FAIL = 'fail';

    /** Nothing has verified this yet — never a pass. See the class note. */
    private const NOT_CHECKABLE = 'not_checkable';

    /** Awaiting a human tick that has not been recorded. */
    private const MANUAL = 'manual';

    /** A human tick is on the record, with a name and a date. */
    private const SIGNED_OFF = 'signed_off';

    /** Overridden on the record, with a reason. Does not block, and is printed loudly. */
    private const WAIVED = 'waived';

    /**
     * The twenty scheduled entries of section 10.4, which GL-41 diffs `schedule:list` against.
     *
     * The table is the authority for both halves — the eight spine and Phase 18 entries as much as
     * the twelve these phases own — because a proof that stops running looks exactly like a proof
     * that stops finding things.
     *
     * `without` exists for one pair only: `backup:verify --latest` and `backup:verify --latest
     * --deep` are different entries doing different work, and the shallow fragments match the deep
     * entry. A daily checksum satisfied by a weekly restore proof would leave six days unchecked.
     *
     * @var list<array{fragments: list<string>, without: string|null}>
     */
    private const SCHEDULED = [
        ['fragments' => ['commissions:sweep'], 'without' => null],
        ['fragments' => ['commissions:release-held'], 'without' => null],
        ['fragments' => ['commission-rules:activate'], 'without' => null],
        ['fragments' => ['fees:mark-overdue'], 'without' => null],
        ['fragments' => ['collaborators:reconcile-wallets'], 'without' => null],
        ['fragments' => ['financial:verify-constraints'], 'without' => null],
        ['fragments' => ['fees:installment-reminders'], 'without' => null],
        ['fragments' => ['payouts:expire-stale-requests'], 'without' => null],
        ['fragments' => ['ops:heartbeat'], 'without' => null],
        ['fragments' => ['ops:check-heartbeats'], 'without' => null],
        ['fragments' => ['ops:prune-logs'], 'without' => null],
        ['fragments' => ['integrity:verify', '--suite=all'], 'without' => null],
        ['fragments' => ['backup:run', '--type=database'], 'without' => null],
        ['fragments' => ['backup:run', '--type=files'], 'without' => null],
        ['fragments' => ['backup:prune'], 'without' => null],
        ['fragments' => ['backup:verify', '--latest'], 'without' => '--deep'],
        ['fragments' => ['backup:verify', '--latest', '--deep'], 'without' => null],
        ['fragments' => ['ops:prune-integrity-runs'], 'without' => null],
        ['fragments' => ['security:audit'], 'without' => null],
        ['fragments' => ['ops:digest'], 'without' => null],
    ];

    /**
     * The four GL-29 entries, which are the ones HD-10 rests on.
     *
     * A subset of self::SCHEDULED, named separately because GL-29 and GL-41 fail for different
     * reasons and an operator fixing one should not have to read the other's output.
     *
     * @var list<string>
     */
    private const FINANCIAL_SCHEDULE = [
        'commissions:sweep',
        'commissions:release-held',
        'collaborators:reconcile-wallets',
        'financial:verify-constraints',
    ];

    /**
     * What `php -m` must list (INSTALL.md step 1).
     *
     * `intl` is deliberately absent: INSTALL.md calls it optional. `bcmath` is the one that is not
     * negotiable — every money figure goes through `App\Support\Money`, which is bcmath, and
     * without it the application does not start.
     *
     * @var list<string>
     */
    private const EXTENSIONS = [
        'bcmath', 'curl', 'exif', 'fileinfo', 'gd', 'json',
        'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'zip',
    ];

    /** Section 6.9.7: below this, PHP silently truncates the role editor's permission matrix. */
    private const MIN_INPUT_VARS = 5000;

    /**
     * One result per command name+arguments, so `security:audit` runs once for GL-11, GL-16 and GL-18.
     *
     * @var array<string, array{exit: int, output: string}>
     */
    private array $commandResults = [];

    /** @var array<string, array{event: string, at: string, by: string|null, note: string|null}>|null */
    private ?array $signOffs = null;

    /**
     * Both services are injected rather than measured again here (HD-3).
     *
     * The health probes and the retention plan have exactly one owner each, so this command and the
     * health screen cannot disagree about whether the queue worker is alive — which is the argument
     * nobody wants to be having on launch day.
     */
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly BackupRetentionService $retention,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = [];

        foreach (GoLiveChecklist::items() as $item) {
            $rows[] = $this->evaluate($item);
        }

        $result = $this->summarise($rows);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return (int) $result['exit_code'];
        }

        $this->render($result);

        return (int) $result['exit_code'];
    }

    /*
    |--------------------------------------------------------------------------
    | Evaluation
    |--------------------------------------------------------------------------
    */

    /**
     * One row's verdict: the machine half, the manual half, and how they combine.
     *
     * **The worse of the two halves wins, and "not recorded" is worse than "not measured".** A row
     * whose command passed but whose manual tick is missing is not green: GL-17's `.php` upload is
     * refused by the application *and* has to be refused by the real Apache, and only one of those
     * two facts is in this process.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function evaluate(array $item): array
    {
        $machine = match ((string) $item['kind']) {
            GoLiveChecklist::KIND_COMMAND => $this->runCommandRow($item),
            GoLiveChecklist::KIND_INSPECT => $this->runInspector($item),
            GoLiveChecklist::KIND_TEST => $this->readSuiteVerdict($item),
            // Null means "no machine half", which is true of KIND_MANUAL and of nothing else. A
            // fifth kind added to the checklist and not handled here must NOT fall through to a
            // pass: an unrecognised kind is a row nothing measured, and the only safe reading of
            // that is unproved.
            GoLiveChecklist::KIND_MANUAL => null,
            default => [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('Unknown check kind [%s] — this gate cannot answer %s.', (string) $item['kind'], (string) $item['id']),
            ],
        };

        $signOff = $item['manual'] ? $this->signOffFor((string) $item['id']) : null;

        $status = $this->combine($machine, $item, $signOff);

        return [
            'id' => $item['id'],
            'group' => $item['group'],
            'title' => $item['title'],
            'blocks' => (bool) $item['blocks'],
            'kind' => $item['kind'],
            'needs_sign_off' => (bool) $item['manual'],
            'status' => $status,
            // "Open" is the only word the exit code cares about. Waived counts as closed - that is
            // what a waiver is - and is reported separately so it can never pass unnoticed.
            'open' => (bool) $item['blocks'] && ! in_array($status, [self::PASS, self::SIGNED_OFF, self::WAIVED], true),
            'machine_status' => $machine['status'] ?? null,
            'detail' => $machine['detail'] ?? null,
            'recheck' => $this->recheckFor($item),
            'evidence' => $item['evidence'],
            'test' => $item['test'],
            'note' => $item['note'],
            'sign_off' => $signOff,
        ];
    }

    /**
     * @param  array{status: string, detail: string}|null  $machine
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $signOff
     */
    private function combine(?array $machine, array $item, ?array $signOff): string
    {
        // A waiver is recorded against the item, not against one of its halves: it overrides the
        // whole row, which is why it is checked before anything else and printed in red.
        if (($signOff['event'] ?? null) === GoLiveChecklist::EVENT_WAIVED) {
            return self::WAIVED;
        }

        if ($machine !== null && $machine['status'] !== self::PASS) {
            return $machine['status'];
        }

        if (! $item['manual']) {
            return self::PASS;
        }

        return $signOff === null ? self::MANUAL : self::SIGNED_OFF;
    }

    /**
     * Run a `command` row, reusing a result this run already produced.
     *
     * **Only exit 0 passes.** Several of these commands mean "warnings, no findings" by exit 1, and
     * a gate that accepts that is a gate whose warnings nobody ever comes back to. The exit code and
     * the last lines of output are carried into the row either way, so the difference is visible to
     * the person who has to act on it.
     *
     * @param  array<string, mixed>  $item
     * @return array{status: string, detail: string}
     */
    private function runCommandRow(array $item): array
    {
        $name = (string) $item['command'];

        /** @var array<string, mixed> $arguments */
        $arguments = $item['arguments'];

        if (! $this->commandExists($name)) {
            // Today's honest answer for every backup and demo command: the slice that owns them has
            // not landed. Naming the command is the whole point - "not checkable" with no subject is
            // indistinguishable from a bug in this gate.
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('`%s` is not registered yet.', $name),
            ];
        }

        $key = $name.' '.json_encode($arguments);

        if (! isset($this->commandResults[$key])) {
            $buffer = new BufferedOutput;

            try {
                $exit = Artisan::call($name, $arguments, $buffer);
            } catch (Throwable $exception) {
                // A command that threw proves nothing. It must not be reported as a failure of the
                // thing the command measures either, or somebody spends an afternoon on the wrong
                // problem.
                $this->commandResults[$key] = [
                    'exit' => -1,
                    'output' => $exception::class.': '.$exception->getMessage(),
                ];

                $exit = -1;
            }

            if ($exit !== -1) {
                $this->commandResults[$key] = ['exit' => $exit, 'output' => $buffer->fetch()];
            }
        }

        $run = $this->commandResults[$key];
        $printable = $this->describeCommand($name, $arguments);

        if ($run['exit'] === 0) {
            return ['status' => self::PASS, 'detail' => sprintf('%s exited 0.', $printable)];
        }

        if ($run['exit'] === -1) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('%s could not run — %s', $printable, $this->excerpt($run['output'])),
            ];
        }

        return [
            'status' => self::FAIL,
            'detail' => sprintf('%s exited %d — %s', $printable, $run['exit'], $this->excerpt($run['output'])),
        ];
    }

    /**
     * Run an `inspect` row's method.
     *
     * The inspector name comes from the checklist, never from input, and a name with no method is a
     * programmer error that must read as "unverified" rather than as a healthy system.
     *
     * @param  array<string, mixed>  $item
     * @return array{status: string, detail: string}
     */
    private function runInspector(array $item): array
    {
        $method = 'inspect'.ucfirst((string) $item['inspector']);

        if (! method_exists($this, $method)) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('No inspector [%s] — this gate cannot answer %s.', $method, $item['id']),
            ];
        }

        try {
            /** @var array{status: string, detail: string} $result */
            $result = $this->{$method}();

            return $result;
        } catch (Throwable $exception) {
            // Every inspector reads something that can be absent: a table before its migration, a
            // disk before its configuration, a settings store before its seeder. None of those is a
            // pass and none of them is a failure of the item.
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('Could not be measured — %s: %s', $exception::class, $exception->getMessage()),
            ];
        }
    }

    /**
     * A `test` row, answered by the verdict the nightly suite recorded.
     *
     * **This is the one place the gate accepts second-hand evidence, and the terms are strict.** The
     * newest `integrity_check_runs` row for the suite must exist, must be inside
     * {@see GoLiveChecklist::SUITE_VERDICT_MAX_HOURS}, and must not block a go-live by its own
     * rule — which for the two financial suites includes a warning
     * ({@see IntegrityCheckStatus::blocksGoLive()}). Anything else is "not yet checkable", naming
     * the test, because a stale green is the most expensive kind of green there is.
     *
     * @param  array<string, mixed>  $item
     * @return array{status: string, detail: string}
     */
    private function readSuiteVerdict(array $item): array
    {
        $suite = GoLiveChecklist::suiteFor((string) $item['id']);
        $test = (string) $item['test'];

        if (! $suite instanceof IntegrityCheckSuite) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('Not yet checkable: %s declares no suite. Proof is %s.', $item['id'], $test),
            ];
        }

        if (! Schema::hasTable('integrity_check_runs')) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf('Not yet checkable: integrity_check_runs does not exist. Proof is %s.', $test),
            ];
        }

        $run = IntegrityCheckRun::query()
            ->forSuite($suite)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof IntegrityCheckRun) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf(
                    'Not yet checkable: no %s suite has been recorded. Proof is %s — run `php artisan integrity:verify --suite=%s`.',
                    $suite->value,
                    $test,
                    $suite->value,
                ),
            ];
        }

        $at = $run->finished_at ?? $run->started_at ?? $run->created_at;

        // `abs()` because Carbon 3's diff is signed and a clock that is briefly ahead of the row's
        // timestamp must not read as a verdict from the future — which would pass.
        $age = $at === null ? null : abs($at->diffInHours(Carbon::now()));

        if ($age === null || $age > GoLiveChecklist::SUITE_VERDICT_MAX_HOURS) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => sprintf(
                    'Not yet checkable: the newest %s verdict is %s, older than the %d-hour window. Proof is %s.',
                    $suite->value,
                    $at === null ? 'undated' : app_datetime($at),
                    GoLiveChecklist::SUITE_VERDICT_MAX_HOURS,
                    $test,
                ),
            ];
        }

        $status = $run->status;

        if ($status instanceof IntegrityCheckStatus && $status->blocksGoLive($suite)) {
            return [
                'status' => self::FAIL,
                'detail' => sprintf(
                    'The %s suite recorded %s on %s (%d failed, %d warned). Proof is %s.',
                    $suite->value,
                    $status->value,
                    app_datetime($at),
                    $run->checks_failed,
                    $run->checks_warned,
                    $test,
                ),
            ];
        }

        return [
            'status' => self::PASS,
            'detail' => sprintf(
                'The %s suite recorded %s on %s (%d checks). Proof is %s.',
                $suite->value,
                $status instanceof IntegrityCheckStatus ? $status->value : 'passed',
                app_datetime($at),
                $run->checks_total,
                $test,
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Manual sign-offs
    |--------------------------------------------------------------------------
    */

    /**
     * The recorded tick or waiver for one item, newest first.
     *
     * Read from `activity_log`, because section 8.5 has the screen write it there and a tick is an
     * audit fact about a person. The whole set is loaded once: fifteen rows each asking their own
     * question would be fifteen queries for a table this gate reads and never writes.
     *
     * @return array<string, mixed>|null
     */
    private function signOffFor(string $id): ?array
    {
        if ($this->signOffs === null) {
            $this->signOffs = $this->loadSignOffs();
        }

        return $this->signOffs[$id] ?? null;
    }

    /**
     * @return array<string, array{event: string, at: string, by: string|null, note: string|null}>
     */
    private function loadSignOffs(): array
    {
        try {
            if (! Schema::hasTable('activity_log')) {
                return [];
            }

            $rows = DB::table('activity_log')
                ->where('log_name', GoLiveChecklist::LOG_NAME)
                ->whereIn('event', [GoLiveChecklist::EVENT_TICKED, GoLiveChecklist::EVENT_WAIVED])
                ->orderByDesc('id')
                ->limit(500)
                ->get(['event', 'properties', 'causer_id', 'created_at']);
        } catch (Throwable) {
            // No database, no ticks. An unreadable log is not a signed-off checklist.
            return [];
        }

        $signOffs = [];

        foreach ($rows as $row) {
            $properties = json_decode((string) ($row->properties ?? '{}'), true);
            $item = is_array($properties) ? (string) ($properties['item'] ?? '') : '';

            // Newest first, so the first row seen for an item is the current one. A later tick
            // replacing an earlier waiver (or the other way round) is therefore what counts.
            if ($item === '' || isset($signOffs[$item])) {
                continue;
            }

            $signOffs[$item] = [
                'event' => (string) $row->event,
                'at' => app_datetime($row->created_at),
                'by' => $row->causer_id === null ? null : $this->userName((int) $row->causer_id),
                'note' => is_array($properties) ? ($properties['note'] ?? null) : null,
            ];
        }

        return $signOffs;
    }

    private function userName(int $id): ?string
    {
        try {
            $name = DB::table('users')->where('id', $id)->value('name');

            return $name === null ? null : (string) $name;
        } catch (Throwable) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Inspectors — Environment
    |--------------------------------------------------------------------------
    */

    /**
     * GL-01. Nothing below this row can be trusted while it is open.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectEnvironment(): array
    {
        $problems = [];

        $env = (string) config('app.env');

        if ($env !== 'production') {
            $problems[] = sprintf('APP_ENV is "%s", not production', $env);
        }

        if (config('app.debug') === true) {
            // The single worst one: a stack trace on an error page names file paths, queries and
            // sometimes the value that broke.
            $problems[] = 'APP_DEBUG is true — every error page leaks the stack';
        }

        $key = (string) config('app.key');

        if ($key === '' || str_contains($key, 'SomeRandomString') || $key === 'base64:') {
            $problems[] = 'APP_KEY is empty or still the example value';
        }

        return $this->verdict($problems, sprintf('APP_ENV=%s, debug off, key set.', $env));
    }

    /**
     * GL-02.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectAppUrl(): array
    {
        $url = (string) config('app.url');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $problems = [];

        if (! str_starts_with($url, 'https://')) {
            $problems[] = sprintf('APP_URL is "%s", not https', $url);
        }

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $problems[] = sprintf('APP_URL host is "%s" — not a real host', $host);
        }

        return $this->verdict($problems, sprintf('APP_URL is %s.', $url));
    }

    /**
     * GL-03. The four artefacts `php artisan about` reports as CACHED.
     *
     * Checked as files rather than by parsing another command's table: the question is whether the
     * cache exists, and that is a fact on disk. Views are the odd one out — the compiled directory
     * holds one file per template, so "any file at all" is the only honest test of `view:cache`.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectCaches(): array
    {
        $missing = [];

        if (! File::exists(base_path('bootstrap/cache/config.php'))) {
            $missing[] = 'config';
        }

        if (File::glob(base_path('bootstrap/cache/routes-*.php')) === []) {
            $missing[] = 'routes';
        }

        if (! File::exists(base_path('bootstrap/cache/events.php'))) {
            $missing[] = 'events';
        }

        $views = storage_path('framework/views');

        if (! File::isDirectory($views) || File::glob($views.'/*.php') === []) {
            $missing[] = 'views';
        }

        return $this->verdict(
            $missing === [] ? [] : ['not cached: '.implode(', ', $missing)],
            'Config, routes, events and views are all cached.',
        );
    }

    /**
     * GL-05. Non-blocking, and still not optional.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectAppVersion(): array
    {
        $version = setting('ops.app_version');

        if ($version === null || trim((string) $version) === '') {
            return [
                'status' => self::FAIL,
                'detail' => 'ops.app_version is unstamped — a restored archive cannot say which release made it.',
            ];
        }

        return [
            'status' => self::PASS,
            'detail' => sprintf('ops.app_version is %s. Confirm it matches the deployed release.', $version),
        ];
    }

    /**
     * GL-06. Extensions, opcache and the one ini value that silently corrupts a role save.
     *
     * **This reads the CLI SAPI's php.ini, and the site is served by another one.** On this
     * installation they are the same file (XAMPP, one `php.ini`, mod_php), which is why the row is
     * machine-checkable at all — but on a host where php-cli and php-fpm carry separate inis, a
     * green here says nothing about the ini Apache loaded. So the detail names the file it read,
     * and the operator compares it against PRODUCTION.md section 7 rather than trusting the word
     * "pass". Naming the blind spot is the whole of the defence: a checklist that reports green for
     * something nothing verified is the single most dangerous artefact in this phase.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectPhpRuntime(): array
    {
        $problems = [];

        $missing = array_values(array_filter(
            self::EXTENSIONS,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        if ($missing !== []) {
            $problems[] = 'missing extensions: '.implode(', ', $missing);
        }

        if (! filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
            $problems[] = 'opcache.enable is off';
        }

        if (filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN)) {
            // With timestamps validated, every request stats every file. Turning it off is what
            // makes a deploy need `httpd -k graceful`, which PRODUCTION.md section 7 spells out.
            $problems[] = 'opcache.validate_timestamps is on — see PRODUCTION.md section 7';
        }

        $inputVars = (int) ini_get('max_input_vars');

        if ($inputVars < self::MIN_INPUT_VARS) {
            $problems[] = sprintf(
                'max_input_vars is %d, below %d — the role permission matrix will be truncated with no error',
                $inputVars,
                self::MIN_INPUT_VARS,
            );
        }

        return $this->verdict($problems, sprintf(
            'PHP %s, every required extension loaded, opcache on, max_input_vars %d (read from %s — '
            .'confirm Apache loads the same file).',
            PHP_VERSION,
            $inputVars,
            php_ini_loaded_file() === false ? 'no php.ini' : (string) php_ini_loaded_file(),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Inspectors — Security
    |--------------------------------------------------------------------------
    */

    /**
     * GL-07, the application's half only. The web server's half is the manual tick.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectHttpsConfigured(): array
    {
        $problems = [];

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL is not https';
        }

        if (! $this->flag('security.force_https')) {
            $problems[] = 'security.force_https is off';
        }

        return $this->verdict(
            $problems,
            'The application asks for https. Confirm Apache 301s and that the three pages are free of mixed content.',
        );
    }

    /**
     * GL-08. Order matters, and this row checks it.
     *
     * HSTS before HTTPS actually serves locks every browser that saw the header out of a site that
     * cannot answer on TLS, and there is no way to un-send it.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectHsts(): array
    {
        $forced = $this->flag('security.force_https');
        $hsts = $this->flag('security.hsts_enabled');

        if (! $forced) {
            return ['status' => self::FAIL, 'detail' => 'security.force_https is off.'];
        }

        if (! $hsts) {
            return [
                'status' => self::FAIL,
                'detail' => 'security.hsts_enabled is off. Turn it on only after GL-07 is green.',
            ];
        }

        return ['status' => self::PASS, 'detail' => 'force_https and HSTS are both on.'];
    }

    /**
     * GL-10. Non-blocking because a report-only CSP still reports.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectCspEnforcing(): array
    {
        if ($this->flag('security.csp_report_only')) {
            return [
                'status' => self::FAIL,
                'detail' => 'CSP is report-only. Enforce it once the report log has been clean for 48 hours.',
            ];
        }

        return ['status' => self::PASS, 'detail' => 'CSP is enforcing.'];
    }

    /**
     * GL-14. Asked of the router, because a route registered by a package is still a route.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectRegistrationClosed(): array
    {
        $problems = [];

        foreach (['register', 'register.store'] as $name) {
            if (Route::has($name)) {
                $problems[] = sprintf('route [%s] is registered — D15 says self-registration does not exist', $name);
            }
        }

        foreach (['password-reset', 'verification'] as $limiter) {
            if (RateLimiter::limiter($limiter) === null) {
                $problems[] = sprintf('no named limiter [%s]', $limiter);
            }
        }

        return $this->verdict($problems, 'No registration route; reset and verification are both throttled.');
    }

    /*
    |--------------------------------------------------------------------------
    | Inspectors — Data
    |--------------------------------------------------------------------------
    */

    /**
     * GL-22, and the one row where the honest answer is "ask the test".
     *
     * **This gate will not run `demo:seed` to see whether it refuses.** The refusal is the only
     * thing standing between a production database and a hundred fake receipts, and a gate that
     * tests a guard by tripping it is a gate that seeds production the one time the guard is broken.
     * So the environment is checked here and the refusal is left to the acceptance test, named in
     * the detail line.
     *
     * @return array{status: string, detail: string}
     */
    /**
     * GL-22, the half a machine can answer.
     *
     * **The row asks two things and only one of them is checkable.** "Demo data absent from
     * production" is a query. "demo:seed refuses to run" is not — proving it means running
     * `demo:seed` against production, and a gate that trips the guard it is testing is not a gate.
     * So this answers the first half and the row carries `manual: true` for the second, the way
     * GL-17 handles its Apache half.
     *
     * **It used to have no PASS branch at all**, which made `golive:check` unable to exit 0 on a
     * perfectly healthy system — and exit 0 is what install step 19 and deploy step 16 wait on. A
     * gate that can never open is a gate people stop running.
     *
     * The marker is `DemoSeeder::EMAIL_DOMAIN`, which the seeder chose for exactly this: its own
     * docblock says one `LIKE` per table finds every row it wrote.
     */
    private function inspectDemoData(): array
    {
        if ((string) config('app.env') !== 'production') {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => 'This row is a statement about production, and demo rows are expected here. '
                    .'Re-run on the target.',
            ];
        }

        $domain = '%@'.DemoSeeder::EMAIL_DOMAIN;
        $found = [];

        // One LIKE per table that carries an address. A demo row anywhere is the finding; the
        // count is what tells an operator whether it is a stray or a whole seeded dataset.
        foreach (['users', 'clients', 'leads', 'students', 'client_contacts'] as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $count = DB::table($table)->where('email', 'like', $domain)->count();

                if ($count > 0) {
                    $found[] = $table.': '.$count;
                }
            } catch (Throwable) {
                // A table without an `email` column is not a finding; it is a table this check has
                // nothing to say about.
            }
        }

        if ($found !== []) {
            return [
                'status' => self::FAIL,
                'detail' => 'Demo rows are present in production ('.implode(', ', $found).'). They carry '
                    .DemoSeeder::EMAIL_DOMAIN.' addresses, so they are findable — and a week from now '
                    .'nobody will be able to tell which client was fictional.',
            ];
        }

        return [
            'status' => self::PASS,
            'detail' => 'No '.DemoSeeder::EMAIL_DOMAIN.' rows in production. The refusal half of this row '
                .'is the operator tick: demo:seed must be witnessed refusing on the deployment test.',
        ];
    }

    /**
     * GL-23. Non-blocking (D11), and still the reason an admission form can have no branch to pick.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectDefaultBranch(): array
    {
        if (! Schema::hasTable('branches')) {
            return ['status' => self::NOT_CHECKABLE, 'detail' => 'Not yet checkable: the branches table does not exist.'];
        }

        $count = DB::table('branches')->count();
        $default = setting('institute.default_branch_id');
        $problems = [];

        if ($count === 0) {
            $problems[] = 'no branch exists';
        }

        if ($default === null || (string) $default === '') {
            $problems[] = 'institute.default_branch_id is not set';
        } elseif (DB::table('branches')->where('id', (int) $default)->doesntExist()) {
            $problems[] = sprintf('institute.default_branch_id points at branch %s, which does not exist', (string) $default);
        }

        return $this->verdict($problems, sprintf('%d branch(es); the default is set and resolves.', $count));
    }

    /*
    |--------------------------------------------------------------------------
    | Inspectors — Financial, Performance
    |--------------------------------------------------------------------------
    */

    /**
     * GL-29. The four entries invariant HD-10 rests on.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectFinancialSchedule(): array
    {
        $registered = $this->scheduledCommands();

        $missing = array_values(array_filter(
            self::FINANCIAL_SCHEDULE,
            static fn (string $command): bool => array_filter(
                $registered,
                static fn (string $entry): bool => str_contains($entry, $command),
            ) === [],
        ));

        return $this->verdict(
            $missing === [] ? [] : ['not scheduled: '.implode(', ', $missing)],
            'All four financial proofs are scheduled.',
        );
    }

    /**
     * GL-31. Read from the log, because production does not throw on a lazy load.
     *
     * Strict mode is off in production so a missed `with()` cannot 500 a paying client; the
     * violation is reported instead. Which is exactly why the absence of an exception proves
     * nothing here and the log has to be read.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectLazyLoadViolations(): array
    {
        $count = $this->countInRecentLogs('Lazy loading violation');

        if ($count === null) {
            return ['status' => self::NOT_CHECKABLE, 'detail' => 'Not yet checkable: no daily log files to read.'];
        }

        if ($count > 0) {
            return [
                'status' => self::FAIL,
                'detail' => sprintf('%d lazy-loading violation(s) logged in the last 24 hours.', $count),
            ];
        }

        return ['status' => self::PASS, 'detail' => 'No lazy-loading violation logged in the last 24 hours.'];
    }

    /*
    |--------------------------------------------------------------------------
    | Inspectors — Backup
    |--------------------------------------------------------------------------
    */

    /**
     * GL-34. Both halves: a schedule, and a row that proves it ran.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectDatabaseBackupPresent(): array
    {
        return $this->inspectBackupOfType('database', 'backup.database_schedule');
    }

    /**
     * GL-35.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectFilesBackupPresent(): array
    {
        return $this->inspectBackupOfType('files', 'backup.files_schedule');
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function inspectBackupOfType(string $type, string $scheduleKey): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return ['status' => self::NOT_CHECKABLE, 'detail' => 'Not yet checkable: the backup_runs table does not exist.'];
        }

        $problems = [];

        if (! $this->flag('backup.enabled')) {
            $problems[] = 'backup.enabled is off, so nothing is scheduled';
        }

        $schedule = (string) (setting($scheduleKey) ?? 'off');

        if ($schedule === 'off' || $schedule === '') {
            $problems[] = sprintf('%s is "off"', $scheduleKey);
        }

        // `full` counts: an archive that contains the database is a database restore point,
        // whatever the operator chose to call the job that made it. Which types those are is asked
        // of the enum rather than written as `['database', 'full']` — the literal list is the exact
        // thing `BackupRun::scopeUsableDatabaseArchives()` refuses to write, because a type added
        // later would then be counted wrongly rather than not at all. `status` likewise comes from
        // {@see BackupStatus}: rule 8, no statuses-as-strings.
        $carries = $type === 'database'
            ? static fn (BackupType $case): bool => $case->includesDatabase()
            : static fn (BackupType $case): bool => $case->includesFiles();

        $completed = BackupRun::query()
            ->whereIn('type', array_map(
                static fn (BackupType $case): string => $case->value,
                array_values(array_filter(BackupType::cases(), $carries)),
            ))
            ->where('status', BackupStatus::Completed->value)
            ->orderByDesc('finished_at')
            ->first();

        if (! $completed instanceof BackupRun) {
            $problems[] = sprintf('no completed %s archive exists', $type);
        }

        return $this->verdict($problems, sprintf(
            '%s backup is scheduled "%s"; newest completed %s.',
            ucfirst($type),
            $schedule,
            $completed?->finished_at === null ? 'unknown' : app_datetime($completed->finished_at),
        ));
    }

    /**
     * GL-36. Invariant HD-6 in one row: a backup is not a backup until it has been restored.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectLatestArchiveRestoreOk(): array
    {
        $archive = $this->newestDatabaseArchive();

        if (! $archive instanceof BackupRun) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => 'Not yet checkable: there is no usable database archive to verify.',
            ];
        }

        if (! $archive->satisfiesGoLive()) {
            return [
                'status' => self::FAIL,
                'detail' => sprintf(
                    'The newest database archive is %s, not restore_ok. Run `php artisan backup:verify --latest --deep`.',
                    $archive->verification_status->value,
                ),
            ];
        }

        return [
            'status' => self::PASS,
            'detail' => sprintf('The newest database archive restored cleanly on %s.', app_datetime($archive->verified_at)),
        ];
    }

    /**
     * GL-37. Only asked while the setting says to ask it.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectOffsiteCopy(): array
    {
        if (! $this->flag('backup.offsite_required_for_go_live')) {
            return [
                'status' => self::PASS,
                'detail' => 'backup.offsite_required_for_go_live is off, so no offsite copy is required. '
                    .'Say so in writing: it means there is no copy of the data outside this building.',
            ];
        }

        $archive = $this->newestDatabaseArchive();

        if (! $archive instanceof BackupRun) {
            return [
                'status' => self::NOT_CHECKABLE,
                'detail' => 'Not yet checkable: there is no usable database archive to copy offsite.',
            ];
        }

        if (! $archive->isCopiedOffsite()) {
            return [
                'status' => self::FAIL,
                'detail' => 'The newest database archive has no offsite copy (offsite_copied_at is null).',
            ];
        }

        return [
            'status' => self::PASS,
            'detail' => sprintf('Copied to %s on %s.', (string) $archive->offsite_disk, app_datetime($archive->offsite_copied_at)),
        ];
    }

    /**
     * GL-39. Headroom below `backup.max_storage_gb`, from the retention service's own plan.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectRetentionHeadroom(): array
    {
        $plan = $this->retention->plan();

        if ($plan->ceilingBreached) {
            return [
                'status' => self::FAIL,
                'detail' => sprintf('The archive store is over backup.max_storage_gb. %s', $plan->describe()),
            ];
        }

        return ['status' => self::PASS, 'detail' => $plan->describe()];
    }

    /*
    |--------------------------------------------------------------------------
    | Inspectors — Operations
    |--------------------------------------------------------------------------
    */

    /**
     * GL-40.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectQueueWorker(): array
    {
        return $this->fromProbe('queue');
    }

    /**
     * GL-41. The heartbeat, plus all twenty entries of section 10.4.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectSchedulerRunning(): array
    {
        $probe = $this->fromProbe('scheduler');
        $registered = $this->scheduledCommands();
        $missing = [];

        foreach (self::SCHEDULED as $expected) {
            $found = false;

            foreach ($registered as $entry) {
                foreach ($expected['fragments'] as $fragment) {
                    if (! str_contains($entry, $fragment)) {
                        continue 2;
                    }
                }

                if ($expected['without'] !== null && str_contains($entry, $expected['without'])) {
                    continue;
                }

                $found = true;

                break;
            }

            if (! $found) {
                $missing[] = implode(' ', $expected['fragments']);
            }
        }

        if ($missing !== []) {
            return [
                'status' => self::FAIL,
                'detail' => sprintf(
                    '%s · %d of %d section-10.4 entries not scheduled: %s',
                    $probe['detail'],
                    count($missing),
                    count(self::SCHEDULED),
                    implode('; ', $missing),
                ),
            ];
        }

        return [
            'status' => $probe['status'],
            'detail' => sprintf('%s · all %d section-10.4 entries are scheduled.', $probe['detail'], count(self::SCHEDULED)),
        ];
    }

    /**
     * GL-42. "Read and explained", not "cleared".
     *
     * @return array{status: string, detail: string}
     */
    private function inspectFailedJobs(): array
    {
        return $this->fromProbe('failed_jobs');
    }

    /**
     * GL-43. The endpoint is enabled and holds a token; whether anybody polls it is the manual half.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectHealthEndpoint(): array
    {
        $problems = [];

        if (! Route::has('ops.health')) {
            $problems[] = 'the ops.health route is not registered';
        }

        if (! $this->flag('ops.health_endpoint_enabled')) {
            $problems[] = 'ops.health_endpoint_enabled is off, so /health answers 404';
        }

        $token = setting('ops.health_check_token');

        if ($token === null || trim((string) $token) === '') {
            // A tokenless health endpoint is an unauthenticated description of the system's
            // internals. It answers 404 without one, which is the point.
            $problems[] = 'ops.health_check_token is not set';
        }

        return $this->verdict($problems, 'The health endpoint is enabled and holds a token. Confirm the monitoring points at it.');
    }

    /**
     * GL-45. The application's dailies only; Apache's logs are the OS's job and the manual half.
     *
     * @return array{status: string, detail: string}
     */
    private function inspectLogRotation(): array
    {
        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return ['status' => self::NOT_CHECKABLE, 'detail' => 'Not yet checkable: there is no logs directory.'];
        }

        $days = max(1, (int) (setting('ops.log_retention_days') ?? 14));
        $cutoff = Carbon::today()->subDays($days);
        $stale = [];

        foreach (File::files($directory) as $file) {
            if (preg_match('/^laravel-(\d{4}-\d{2}-\d{2})\.log$/', $file->getFilename(), $matches) !== 1) {
                continue;
            }

            if (Carbon::parse($matches[1])->lessThan($cutoff)) {
                $stale[] = $file->getFilename();
            }
        }

        if ($stale !== []) {
            return [
                'status' => self::FAIL,
                'detail' => sprintf(
                    '%d daily log(s) older than ops.log_retention_days (%d): %s. Run `php artisan ops:prune-logs`.',
                    count($stale),
                    $days,
                    implode(', ', array_slice($stale, 0, 4)),
                ),
            ];
        }

        return [
            'status' => self::PASS,
            'detail' => sprintf('No application daily older than %d days. Confirm Apache\'s logs rotate too.', $days),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared readings
    |--------------------------------------------------------------------------
    */

    /**
     * A health probe, translated into this command's vocabulary.
     *
     * **Amber is not a pass here, and that is a deliberate difference from `ops:check-heartbeats`.**
     * That command must not page somebody for a worker nobody has started yet. This one is deciding
     * whether to launch, and "the heartbeat has never been stamped" is not a system with a queue
     * worker running as a service.
     *
     * @return array{status: string, detail: string}
     */
    private function fromProbe(string $key): array
    {
        $probe = $this->health->probe($key);
        $detail = trim(sprintf('%s — %s', (string) $probe['status'], (string) ($probe['detail'] ?? '')), ' —');

        return [
            'status' => $probe['status'] === SystemHealthService::STATUS_OK ? self::PASS : self::FAIL,
            'detail' => $detail,
        ];
    }

    private function newestDatabaseArchive(): ?BackupRun
    {
        if (! Schema::hasTable('backup_runs')) {
            return null;
        }

        return BackupRun::query()->usableDatabaseArchives()->orderByDesc('finished_at')->first();
    }

    /**
     * Every registered schedule entry as a printable command string.
     *
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $entries = [];

        foreach (app(Schedule::class)->events() as $event) {
            $command = (string) ($event->command ?? '');

            // A closure entry has no command string; its description is all there is, and an entry
            // registered as a closure is not one of section 10.4's twenty anyway.
            $entries[] = $command !== '' ? $command : (string) $event->getSummaryForDisplay();
        }

        return $entries;
    }

    /**
     * How many lines matching `$needle` the last 24 hours of daily logs hold, or null when there are none to read.
     *
     * Today's and yesterday's file, because a 24-hour window straddles midnight. The match is on the
     * message, not on a parsed timestamp: a stack trace spans lines and a line-oriented parser that
     * tried to date every line would count the trace, not the error.
     */
    private function countInRecentLogs(string $needle): ?int
    {
        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return null;
        }

        $files = [];

        foreach ([Carbon::today(), Carbon::yesterday()] as $day) {
            $path = $directory.'/laravel-'.$day->toDateString().'.log';

            if (File::exists($path)) {
                $files[] = $path;
            }
        }

        if ($files === []) {
            return null;
        }

        $cutoff = Carbon::now()->subDay();
        $count = 0;

        foreach ($files as $path) {
            foreach ($this->logLines($path) as $line) {
                if (! str_contains($line, $needle)) {
                    continue;
                }

                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $matches) === 1
                    && Carbon::parse($matches[1])->lessThan($cutoff)) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * One line at a time, so a 200 MB log does not become 200 MB of memory.
     *
     * @return \Generator<int, string>
     */
    private function logLines(string $path): \Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            // A log locked by Apache on Windows is the common case, and not worth failing a row for.
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A boolean setting, read the way a gate must read one.
     *
     * `"0"`, `"false"` and `"off"` all arrive from a form or a raw update at some point in a
     * system's life, and `(bool) "0"` is false while `(bool) "false"` is true — which would report a
     * disabled control as enabled.
     */
    private function flag(string $key): bool
    {
        return filter_var($this->settingOrNull($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * A setting, or null when the store cannot answer.
     *
     * Null is a real answer and every caller reads it as one: an unreadable setting is never a
     * control that is switched on.
     */
    private function settingOrNull(string $key): mixed
    {
        try {
            return setting($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function commandExists(string $name): bool
    {
        return array_key_exists($name, Artisan::all());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function describeCommand(string $name, array $arguments): string
    {
        $printable = $name;

        foreach ($arguments as $key => $value) {
            $printable .= ' '.(is_bool($value) ? (string) $key : $key.'='.(string) $value);
        }

        return $printable;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function recheckFor(array $item): ?string
    {
        if ($item['kind'] === GoLiveChecklist::KIND_COMMAND) {
            return 'php artisan '.$this->describeCommand((string) $item['command'], $item['arguments']);
        }

        if ($item['kind'] === GoLiveChecklist::KIND_TEST && $item['suite'] !== null) {
            return sprintf('php artisan integrity:verify --suite=%s', (string) $item['suite']);
        }

        if ($item['kind'] === GoLiveChecklist::KIND_INSPECT) {
            return 'php artisan golive:check';
        }

        return null;
    }

    /**
     * The last useful words of a command's output.
     *
     * The tail rather than the head: these commands print their table first and their verdict last,
     * and the verdict is the sentence somebody needs.
     */
    private function excerpt(string $output): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            return 'no output';
        }

        return mb_substr(implode(' | ', array_slice($lines, -2)), 0, 220);
    }

    /**
     * @param  list<string>  $problems
     * @return array{status: string, detail: string}
     */
    private function verdict(array $problems, string $whenClean): array
    {
        // Not `ucfirst()`: half of these sentences begin with a setting key, and "Ops.app_version"
        // is a key that does not exist. A lower-case opening is a smaller wrong than a wrong name.
        return $problems === []
            ? ['status' => self::PASS, 'detail' => $whenClean]
            : ['status' => self::FAIL, 'detail' => implode('; ', $problems).'.'];
    }

    /*
    |--------------------------------------------------------------------------
    | Result and rendering
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarise(array $rows): array
    {
        $counts = [
            self::PASS => 0,
            self::FAIL => 0,
            self::NOT_CHECKABLE => 0,
            self::MANUAL => 0,
            self::SIGNED_OFF => 0,
            self::WAIVED => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }

        $blockersOpen = array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['open']));
        $othersOpen = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! $row['blocks']
                && ! in_array($row['status'], [self::PASS, self::SIGNED_OFF, self::WAIVED], true),
        ));

        // Exit 2 while any blocker is open (section 6.6). Exit 1 when only a non-blocker is - a
        // release can proceed, and somebody still owes an answer.
        $exit = match (true) {
            $blockersOpen !== [] => 2,
            $othersOpen !== [] => 1,
            default => 0,
        };

        return [
            'status' => match ($exit) {
                0 => 'ready',
                1 => 'attention',
                default => 'blocked',
            },
            'exit_code' => $exit,
            'generated_at' => Carbon::now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            // Wrapped: the summary line must still print on a system whose settings table is not
            // there yet, which is precisely the system somebody runs this on first.
            'app_version' => $this->settingOrNull('ops.app_version'),
            'counts' => $counts,
            'blockers_open' => array_map(static fn (array $row): string => (string) $row['id'], $blockersOpen),
            'non_blockers_open' => array_map(static fn (array $row): string => (string) $row['id'], $othersOpen),
            // The contract's own arithmetic, so a row added here and not to docs/GO-LIVE.md shows up
            // as a disagreement rather than as nothing at all.
            'declared' => GoLiveChecklist::counts(),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function render(array $result): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $result['rows'];

        foreach (GoLiveChecklist::byGroup() as $group => $items) {
            $ids = array_column($items, 'id');

            $this->newLine();
            $this->line(sprintf(' <options=bold>%s</>', mb_strtoupper($group)));

            $table = [];

            foreach ($rows as $row) {
                if (! in_array($row['id'], $ids, true)) {
                    continue;
                }

                $table[] = [
                    $this->marker((string) $row['status'], (bool) $row['blocks']),
                    (string) $row['id'],
                    mb_substr((string) $row['title'], 0, 64),
                    mb_substr((string) ($row['detail'] ?? $row['evidence']), 0, 78),
                ];
            }

            $this->table(['', '#', 'item', 'evidence'], $table);
        }

        $this->renderSignOffs($rows);
        $this->renderVerdict($result, $rows);
    }

    /**
     * The rows somebody has to put their name against.
     *
     * Printed as its own block rather than left in the table, because that is the whole reason this
     * command prints manual rows at all: a tick nobody was shown is a tick nobody takes.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderSignOffs(array $rows): void
    {
        $waiting = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['needs_sign_off'] && $row['status'] === self::MANUAL,
        ));

        if ($waiting === []) {
            return;
        }

        $this->newLine();
        $this->line(' <options=bold>AWAITING SIGN-OFF</> — a sentence with a name and a date, in DEVELOPMENT_LOG.md section 9');

        foreach ($waiting as $row) {
            $this->line(sprintf(
                '  <fg=yellow>%s</> %s',
                (string) $row['id'],
                mb_substr((string) $row['title'], 0, 100),
            ));
            $this->line(sprintf('        evidence: %s', (string) $row['evidence']));
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderVerdict(array $result, array $rows): void
    {
        $counts = $result['counts'];

        $this->newLine();
        $this->line(sprintf(
            ' %d items · %d pass · %d signed off · %d fail · %d not yet checkable · %d awaiting sign-off · %d waived',
            $result['declared']['items'],
            $counts[self::PASS],
            $counts[self::SIGNED_OFF],
            $counts[self::FAIL],
            $counts[self::NOT_CHECKABLE],
            $counts[self::MANUAL],
            $counts[self::WAIVED],
        ));

        $waived = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === self::WAIVED));

        if ($waived !== []) {
            // A waiver is legitimate and is never quiet. Section 8.5: the checklist can be
            // overridden, but never silently.
            $this->newLine();
            $this->line(' <fg=red;options=bold>WAIVED</> — each of these was overridden by a person, on the record:');

            foreach ($waived as $row) {
                $this->line(sprintf(
                    '  %s — %s (%s%s)',
                    (string) $row['id'],
                    (string) ($row['sign_off']['note'] ?? 'no reason recorded'),
                    (string) ($row['sign_off']['by'] ?? 'unknown'),
                    isset($row['sign_off']['at']) ? ', '.(string) $row['sign_off']['at'] : '',
                ));
            }
        }

        $open = $result['blockers_open'];

        $this->newLine();

        if ($open === []) {
            if ($result['non_blockers_open'] === []) {
                $this->components->info(sprintf(
                    'No blocker is open. Ran %s in %s, version %s.',
                    app_datetime(Carbon::now()),
                    (string) $result['environment'],
                    (string) ($result['app_version'] ?? 'unstamped'),
                ));

                return;
            }

            $this->components->warn(sprintf(
                '%d non-blocking item(s) open: %s. Each still gets an answer — done, or a date by which it will be.',
                count($result['non_blockers_open']),
                implode(', ', $result['non_blockers_open']),
            ));

            return;
        }

        $this->components->error(sprintf('%d blocker(s) open: %s', count($open), implode(', ', $open)));

        foreach ($rows as $row) {
            if (! $row['open']) {
                continue;
            }

            $this->line(sprintf('  <fg=red>%s</> %s', (string) $row['id'], (string) ($row['detail'] ?? $row['evidence'])));

            if ($row['recheck'] !== null) {
                $this->line(sprintf('        re-check: %s', (string) $row['recheck']));
            }
        }

        $this->newLine();
        $this->line(' A blocker is fixed, never documented away (HD-1). Loosening a check to make this');
        $this->line(' command green is a review failure, not a configuration change — see docs/GO-LIVE.md.');
    }

    private function marker(string $status, bool $blocks): string
    {
        return match ($status) {
            self::PASS => '<fg=green>pass</>',
            self::SIGNED_OFF => '<fg=green>signed</>',
            self::WAIVED => '<fg=red>waived</>',
            self::MANUAL => '<fg=yellow>manual</>',
            self::NOT_CHECKABLE => $blocks ? '<fg=red>unproved</>' : '<fg=yellow>unproved</>',
            default => $blocks ? '<fg=red>FAIL</>' : '<fg=yellow>fail</>',
        };
    }
}
