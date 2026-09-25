<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\BackupStatus;
use App\Enums\ReconciliationStatus;
use App\Services\Ops\SystemHealthService;
use App\Services\Support\NotificationService;
use App\Support\Money;
use App\Support\NotificationRegistry;
use App\Support\Ops\ManifestAuditor;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `ops:digest` — tier-1 monitoring, and the whole of it (phase-24-25 section 6.9.7, section 10.4).
 *
 * **The contract buys no monitoring service, so this message is the only thing standing between a
 * problem and nobody knowing about it.** Section 6.9.7 is explicit: tier 1 is a `ReportCriticalError`
 * listener plus this digest. Tier 2 — an external monitor behind `ops.error_monitoring_enabled` — is
 * optional and may never be switched on. Everything below therefore has to work on a system where
 * this daily message is the entire alerting story.
 *
 * **A digest nobody reads is worse than none**, because it manufactures the belief that somebody
 * would be told. Three rules follow from that and every one of them is load-bearing:
 *
 *   1. **It leads with what changed for the worse.** Yesterday's figures are kept, so "eleven failed
 *      jobs" reads differently from "eleven failed jobs, four more than yesterday" — and only the
 *      second one is a reason to open the file.
 *   2. **It is short.** Sections that are fine are not printed at all. A message with nine green
 *      paragraphs teaches the reader to skim, and a skimmed digest is an unread digest with extra
 *      steps.
 *   3. **"Nothing to report" is one line.** Not an empty message and not a silence: on a good day
 *      the digest still arrives, because a digest that only arrives when something is wrong is
 *      indistinguishable from a scheduler that has stopped.
 *
 * **It says what it cannot see.** A blind spot printed as a footnote is honest; a blind spot left out
 * reads as "nothing happened". Rate-limited responses are the current example — nothing in this
 * application logs a 429, so the digest says the count is unknown rather than reporting zero.
 *
 * **Delivery goes through {@see NotificationService}, never `Mail::to()`.** That service is the only
 * place that knows the module switch, the audience permission and each recipient's channel
 * preferences (INV-22-7). A command that mailed directly would keep arriving for somebody who had
 * muted it, which is the one bug that makes people filter the sender rather than complain.
 *
 * **Registered hourly, and the command decides whether this is its hour.** `ops.error_digest_time` is
 * a setting, and the scheduler is built at boot, so the cadence cannot be a fluent call — see the
 * note in `routes/console.php`. `--force` is how GL-44 is satisfied: an operator sends it on demand
 * and the real recipient confirms the real message arrived.
 *
 * Exit code is always **0** (section 6.6). A digest that failed the scheduler because the mailer was
 * down would take the heartbeat entries down with it, and the silence would then be about the
 * scheduler rather than about the mail.
 */
final class OpsDigest extends Command
{
    /**
     * `--force` and `--dry-run` are additive to section 6.6's bare `ops:digest`.
     *
     * `--force` exists because GL-44 requires a named person to confirm receipt of a real digest, and
     * without it the only way to send one is to wait for 07:00. `--dry-run` assembles and prints and
     * sends nothing, which is how you read tomorrow's digest today.
     */
    protected $signature = 'ops:digest
                            {--force : Send now, whatever the hour, and even if one already went today}
                            {--dry-run : Assemble and print it; send nothing and stamp nothing}
                            {--json : Machine-readable output}';

    protected $description = 'Send the daily operations digest: errors, failed jobs, backups, reconciliation, drift, N+1s and slow queries.';

    /** Nothing here needs attention. Not printed in the body. */
    private const OK = 'ok';

    /** Somebody should look this week. */
    private const WARN = 'warn';

    /** Somebody should look today. */
    private const ALARM = 'alarm';

    /** The window every section measures. One day, so "since the last digest" and "recently" agree. */
    private const WINDOW_HOURS = 24;

    /** Yesterday's figures, so "changed for the worse" is a fact rather than an impression. */
    private const METRICS_KEY = 'ops:digest:metrics';

    /** One digest per day. Written only after a successful dispatch. */
    private const SENT_KEY_PREFIX = 'ops:digest:sent:';

    /**
     * How many distinct labels a section prints.
     *
     * Three. The fourth-most-common error class has never once been the reason somebody opened a
     * digest, and every line past the third makes the first three harder to see.
     */
    private const TOP_N = 3;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SystemHealthService $health,
        private readonly ManifestAuditor $manifests,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (! $force && ! $this->enabled()) {
            $this->info('ops:digest — ops.error_digest_enabled is off; nothing sent.');

            return self::SUCCESS;
        }

        if (! $force && ! $dryRun && ! $this->isItsHour()) {
            // The quiet path, eleven times out of twelve. Not a warning: the schedule is hourly by
            // design and "this is not my hour" is the expected answer.
            return self::SUCCESS;
        }

        if (! $force && ! $dryRun && $this->alreadySentToday()) {
            $this->info('ops:digest — already sent today.');

            return self::SUCCESS;
        }

        $sections = $this->gather();
        $digest = $this->compose($sections);

        if ($this->option('json')) {
            $this->line((string) json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderToConsole($digest);
        }

        if ($dryRun) {
            $this->info('ops:digest — dry run: nothing sent, nothing stamped.');

            return self::SUCCESS;
        }

        $this->deliver($digest);
        $this->remember($sections);

        Cache::put(self::SENT_KEY_PREFIX.Carbon::today()->toDateString(), Carbon::now()->toIso8601String(), Carbon::now()->addDays(2));

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Gating
    |--------------------------------------------------------------------------
    */

    private function enabled(): bool
    {
        return filter_var($this->setting('ops.error_digest_enabled', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Is this the hour `ops.error_digest_time` names?
     *
     * The hour, not the minute: the scheduler fires this entry once an hour, so demanding the minute
     * would mean a digest that arrives on the days the queue happened to be idle at :00.
     */
    private function isItsHour(): bool
    {
        $time = (string) ($this->setting('ops.error_digest_time', '07:00') ?? '07:00');

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches) !== 1) {
            // A malformed setting must not silence the digest for ever. 07:00 is the registry
            // default and the contract's stated time.
            return Carbon::now()->hour === 7;
        }

        return Carbon::now()->hour === (int) $matches[1];
    }

    private function alreadySentToday(): bool
    {
        try {
            return Cache::has(self::SENT_KEY_PREFIX.Carbon::today()->toDateString());
        } catch (Throwable) {
            // An unreachable cache must not become a reason to send nothing. A duplicate digest is a
            // nuisance; a missing one is a blind day.
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Gathering
    |--------------------------------------------------------------------------
    */

    /**
     * The eight readings of section 6.6, each as one section.
     *
     * Section shape: key, label, severity, metric (the comparable integer), headline (one sentence),
     * lines (at most self::TOP_N), remediation (what to do), blind (a sentence, when the reading is
     * not instrumented).
     *
     * Every gatherer is wrapped: a section that throws becomes a line saying it could not be
     * measured. **A digest that dies on its fourth section delivers nothing at all**, which is
     * strictly worse than seven sections and an apology.
     *
     * @return array<string, array<string, mixed>>
     */
    private function gather(): array
    {
        $sections = [];

        foreach ([
            'errors' => 'gatherErrors',
            'failed_jobs' => 'gatherFailedJobs',
            'backups' => 'gatherBackups',
            'reconciliation' => 'gatherReconciliation',
            'manifest' => 'gatherManifestDrift',
            'lazy_loads' => 'gatherLazyLoads',
            'slow_queries' => 'gatherSlowQueries',
            'rate_limits' => 'gatherRateLimits',
        ] as $key => $method) {
            try {
                $sections[$key] = $this->{$method}();
            } catch (Throwable $exception) {
                $sections[$key] = $this->section(
                    key: $key,
                    label: ucfirst(str_replace('_', ' ', $key)),
                    severity: self::WARN,
                    metric: 0,
                    headline: 'could not be measured ('.$exception::class.')',
                );
            }
        }

        return $sections;
    }

    /**
     * Errors by class, from the last day of daily logs.
     *
     * The log is the source rather than a table, because the log is where every error already lands
     * and a second store would be a second thing that can be out of date. The class is pulled from
     * the `{"exception":"[object] (Class(code: 0)` fragment Monolog writes; a line without one is
     * labelled by the first words of its message, which is what an operator would call it anyway.
     *
     * @return array<string, mixed>
     */
    private function gatherErrors(): array
    {
        $counts = [];
        $total = 0;

        foreach ($this->recentLogLines() as $line) {
            if (preg_match('/\]\s+[\w-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $total++;
            $label = $this->errorLabel((string) $matches[2]);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        if ($total === 0) {
            return $this->section('errors', 'Errors', self::OK, 0, 'no error logged in the last 24 hours');
        }

        return $this->section(
            key: 'errors',
            label: 'Errors',
            // Any CRITICAL-or-worse volume is a today problem; a handful of ERROR lines is a
            // this-week problem. The threshold is deliberately low: ten errors a day is a bug
            // somebody is living with.
            severity: $total >= 10 ? self::ALARM : self::WARN,
            metric: $total,
            headline: sprintf('%d error line(s) in the last 24 hours, %d distinct class(es)', $total, count($counts)),
            lines: $this->top($counts),
            remediation: 'storage/logs — read the newest daily first.',
        );
    }

    private function errorLabel(string $message): string
    {
        if (preg_match('/\(([A-Za-z0-9_\\\\]+)\(code:/', $message, $matches) === 1) {
            return class_basename(str_replace('\\\\', '\\', $matches[1]));
        }

        // No exception object: an explicit Log::error() from our own code. Its first clause is the
        // label, and the clause is what we wrote, so it groups cleanly.
        $head = preg_split('/[{:|]/', $message)[0] ?? $message;

        return mb_substr(trim($head), 0, 60);
    }

    /**
     * `failed_jobs`, total and in the window.
     *
     * **The total matters as much as the window.** A row from three weeks ago that nobody read is
     * still work that never happened, and if it was a commission job it is still money the sweeper
     * has not been asked to re-queue (GL-42).
     *
     * @return array<string, mixed>
     */
    private function gatherFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->section('failed_jobs', 'Failed jobs', self::OK, 0, 'no failed_jobs table');
        }

        $total = (int) DB::table('failed_jobs')->count();

        if ($total === 0) {
            return $this->section('failed_jobs', 'Failed jobs', self::OK, 0, 'failed_jobs is empty');
        }

        $since = Carbon::now()->subHours(self::WINDOW_HOURS);
        $recent = (int) DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();

        $counts = [];

        foreach (DB::table('failed_jobs')->orderByDesc('id')->limit(200)->pluck('exception') as $exception) {
            $label = mb_substr(trim(strtok((string) $exception, ":\n") ?: 'unknown'), 0, 60);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $threshold = (int) ($this->setting('ops.failed_jobs_alert_threshold', 5) ?? 5);

        return $this->section(
            key: 'failed_jobs',
            label: 'Failed jobs',
            severity: $total >= max(1, $threshold) ? self::ALARM : self::WARN,
            metric: $total,
            headline: sprintf('%d failed job(s), %d of them in the last 24 hours', $total, $recent),
            lines: $this->top($counts),
            remediation: 'php artisan queue:failed — read the rows, fix the cause, then retry. Never queue:flush.',
        );
    }

    /**
     * The newest database archive: how old it is, and whether it has been restored.
     *
     * @return array<string, mixed>
     */
    private function gatherBackups(): array
    {
        $probe = $this->health->probe('backup');

        $failed = Schema::hasTable('backup_runs')
            // `DB::table` rather than the model because this counts rows and must not hydrate an
            // append-only model to do it — but the value still comes from {@see BackupStatus}
            // (rule 8: statuses are enums, and a literal 'failed' here survives a rename silently).
            ? (int) DB::table('backup_runs')
                ->where('status', BackupStatus::Failed->value)
                ->where('created_at', '>=', Carbon::now()->subHours(self::WINDOW_HOURS))
                ->count()
            : 0;

        $severity = match (true) {
            $failed > 0 => self::ALARM,
            $probe['status'] === SystemHealthService::STATUS_FAILED => self::ALARM,
            $probe['status'] === SystemHealthService::STATUS_DEGRADED => self::WARN,
            default => self::OK,
        };

        $lines = [];

        if ($failed > 0) {
            $lines[] = sprintf('%d backup run(s) failed in the last 24 hours', $failed);
        }

        return $this->section(
            key: 'backups',
            label: 'Backups',
            severity: $severity,
            // The metric is the archive's age in hours: it is the number that gets worse on its own
            // while nothing at all happens, which is exactly the failure a daily digest is for.
            metric: (int) ($probe['value'] ?? 0),
            headline: (string) ($probe['detail'] ?? 'no reading'),
            lines: $lines,
            remediation: (string) ($probe['remediation'] ?? 'php artisan backup:run --type=database'),
        );
    }

    /**
     * The last wallet reconciliation: drift, structural failures, and the closed identity.
     *
     * **The sum is the database's, on a `decimal(15,2)` column, and never PHP's** (CLAUDE.md section
     * 1 rule 4). `SUM()` over a decimal in MariaDB is exact; adding the same column in PHP would be
     * float arithmetic on money, which is the one thing this codebase does not do. The figure only
     * ever leaves here through {@see Money::format()}.
     *
     * Nothing is recomputed here either (INV-26). The reconciliation rows are the proof; a digest
     * that re-derived a balance could disagree with the proof, and then there would be two answers.
     *
     * @return array<string, mixed>
     */
    private function gatherReconciliation(): array
    {
        if (! Schema::hasTable('collaborator_wallet_reconciliations')) {
            return $this->section('reconciliation', 'Wallet reconciliation', self::OK, 0, 'no reconciliation table');
        }

        $latest = DB::table('collaborator_wallet_reconciliations')->max('checked_at');

        if ($latest === null) {
            return $this->section(
                key: 'reconciliation',
                label: 'Wallet reconciliation',
                severity: self::WARN,
                metric: 0,
                headline: 'no reconciliation has ever run',
                remediation: 'php artisan collaborators:reconcile-wallets',
            );
        }

        $runUuid = (string) DB::table('collaborator_wallet_reconciliations')
            ->where('checked_at', $latest)
            ->value('run_uuid');

        $rows = DB::table('collaborator_wallet_reconciliations')->where('run_uuid', $runUuid);

        $checked = (int) $rows->clone()->count();
        $drift = Money::of((string) ($rows->clone()->sum('drift_total') ?? '0'));

        /*
        | The set is {@see ReconciliationStatus::needsAttention()}'s — Drift and Failed — written out
        | because this is a `DB::table` query with no model scope to borrow.
        |
        | **Not `!= 'ok'`.** That reading counts `repaired`, and a repaired wallet is one whose drift
        | was found and corrected: the row is the proof that the system worked. Alarming on it would
        | put a red section in every digest for the rest of the week after a successful repair, and
        | a digest that cries wolf about its own successes is the one people stop opening — which is
        | the failure this whole command exists to avoid.
        */
        $structural = (int) $rows->clone()->whereIn('status', [
            ReconciliationStatus::Drift->value,
            ReconciliationStatus::Failed->value,
        ])->count();

        $identity = (int) $rows->clone()->where('identity_holds', false)->count();

        $age = Carbon::parse((string) $latest)->diffInHours(Carbon::now());
        $stale = abs($age) > 36;

        $severity = match (true) {
            ! Money::isZero($drift) || $structural > 0 || $identity > 0 => self::ALARM,
            $stale => self::WARN,
            default => self::OK,
        };

        $lines = [];

        if (! Money::isZero($drift)) {
            $lines[] = sprintf('drift %s across %d wallet(s)', Money::format($drift), $checked);
        }

        if ($structural > 0) {
            $lines[] = sprintf('%d wallet(s) with a structural failure', $structural);
        }

        if ($identity > 0) {
            // lifetime = pending + available + reserved + paid. When that breaks, the cache and the
            // ledger are telling different stories about money that exists.
            $lines[] = sprintf('%d wallet(s) where the closed identity does not hold', $identity);
        }

        if ($stale) {
            // Said out loud, because a clean-looking headline with no lines under it reads as "this
            // is fine" — and the reason this section is in the digest at all is that the proof has
            // stopped running, which is how a month-seven regression reaches a client.
            $lines[] = sprintf('the last run was %d hours ago; it should run nightly at 01:30', (int) abs($age));
        }

        return $this->section(
            key: 'reconciliation',
            label: 'Wallet reconciliation',
            severity: $severity,
            metric: $structural + $identity,
            headline: sprintf('%d wallet(s) checked %s', $checked, app_datetime($latest)),
            lines: $lines,
            remediation: 'A wrong figure is corrected by a reversing entry that references the original — never by adjusting a wallet.',
        );
    }

    /**
     * Manifest drift: a route, upload or index with no row, or a row whose thing is gone.
     *
     * @return array<string, mixed>
     */
    private function gatherManifestDrift(): array
    {
        $findings = $this->manifests->audit();

        $drift = 0;
        $warnings = 0;
        $lines = [];

        foreach ($findings as $manifest => $finding) {
            $missing = count($finding['missing']);
            $orphaned = count($finding['orphaned']);
            $drift += $missing + $orphaned;
            $warnings += count($finding['warnings']);

            if ($missing + $orphaned > 0) {
                $lines[] = sprintf('%s: %d missing, %d orphaned', (string) $manifest, $missing, $orphaned);
            }
        }

        if ($drift === 0 && $warnings === 0) {
            return $this->section('manifest', 'Manifest drift', self::OK, 0, 'every route, upload and index is accounted for');
        }

        return $this->section(
            key: 'manifest',
            label: 'Manifest drift',
            severity: $drift > 0 ? self::ALARM : self::WARN,
            metric: $drift,
            headline: sprintf('%d drift finding(s), %d warning(s)', $drift, $warnings),
            lines: array_slice($lines, 0, self::TOP_N),
            remediation: 'php artisan audit:manifest --check',
        );
    }

    /**
     * Lazy-load violations logged in the window.
     *
     * Strict mode is off in production so a missed `with()` cannot 500 a paying client; the violation
     * is reported instead (section 6.4). **So in production this log line is the only evidence an
     * N+1 exists**, and a digest that did not carry it would mean production never tells anybody.
     *
     * @return array<string, mixed>
     */
    private function gatherLazyLoads(): array
    {
        $counts = [];
        $total = 0;

        foreach ($this->recentLogLines() as $line) {
            if (! str_contains($line, 'Lazy loading violation')) {
                continue;
            }

            $total++;

            $label = preg_match('/"relation":"([^"]+)"/', $line, $matches) === 1
                ? (string) $matches[1]
                : 'unknown relation';

            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        if ($total === 0) {
            return $this->section('lazy_loads', 'N+1 (lazy loading)', self::OK, 0, 'none logged in the last 24 hours');
        }

        return $this->section(
            key: 'lazy_loads',
            label: 'N+1 (lazy loading)',
            severity: $total >= 50 ? self::ALARM : self::WARN,
            metric: $total,
            headline: sprintf('%d lazy-load violation(s) in the last 24 hours', $total),
            lines: $this->top($counts),
            remediation: 'php artisan perf:budget --all — the log line names the route and the relation.',
        );
    }

    /**
     * Slow queries logged in the window, grouped by route.
     *
     * The route is the grouping and not the SQL. The SQL shape is in the log for whoever opens it;
     * the route is what tells an operator which screen is slow, and it is the only one of the two
     * that is safe to carry into a notification (section 10.3 — no row of business data).
     *
     * @return array<string, mixed>
     */
    private function gatherSlowQueries(): array
    {
        $counts = [];
        $total = 0;

        foreach ($this->recentLogLines() as $line) {
            if (! str_contains($line, 'Slow query threshold exceeded')) {
                continue;
            }

            $total++;

            $label = preg_match('/"route":"([^"]+)"/', $line, $matches) === 1
                ? (string) $matches[1]
                : 'unnamed route';

            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        if ($total === 0) {
            return $this->section('slow_queries', 'Slow queries', self::OK, 0, 'none over the threshold in the last 24 hours');
        }

        $threshold = (int) ($this->setting('ops.slow_query_ms', 250) ?? 250);

        return $this->section(
            key: 'slow_queries',
            label: 'Slow queries',
            severity: $total >= 100 ? self::ALARM : self::WARN,
            metric: $total,
            headline: sprintf('%d request(s) over %d ms in the last 24 hours', $total, $threshold),
            lines: $this->top($counts),
            remediation: 'The log line names the route and the statement shapes; bindings are never logged.',
        );
    }

    /**
     * Rate-limited responses — the reading this system cannot currently take.
     *
     * **Nothing in this application logs a 429.** `ThrottleRequestsException` is an `HttpException`,
     * which the framework's `dontReport` list excludes, so a throttled request leaves no trace in
     * `storage/logs` at all. Reporting "0 rate-limit hits" would therefore be a lie of exactly the
     * kind this phase is most careful about: a green figure nothing measured.
     *
     * So the section counts the marker it *would* count, and when it finds none it declares itself
     * blind instead of clean. The footnote survives until something writes that marker — a one-line
     * `Log::warning()` in a throttle-response handler is all it needs.
     *
     * @return array<string, mixed>
     */
    private function gatherRateLimits(): array
    {
        $total = 0;

        foreach ($this->recentLogLines() as $line) {
            if (str_contains($line, 'Rate limit exceeded')) {
                $total++;
            }
        }

        if ($total === 0) {
            return $this->section(
                key: 'rate_limits',
                label: 'Rate limiting',
                severity: self::OK,
                metric: 0,
                headline: 'not instrumented',
                blind: 'Rate-limited responses (429) are not logged, so the count is unknown, not zero.',
            );
        }

        return $this->section(
            key: 'rate_limits',
            label: 'Rate limiting',
            severity: $total >= 100 ? self::ALARM : self::WARN,
            metric: $total,
            headline: sprintf('%d rate-limited response(s) in the last 24 hours', $total),
            remediation: 'A spike on a public form is usually abuse; on an admin route it is usually a loop in our own code.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Composing
    |--------------------------------------------------------------------------
    */

    /**
     * Turn the sections into the message that gets sent.
     *
     * **The order is the message.** Worse-than-yesterday alarms first, then alarms, then
     * worse-than-yesterday warnings, then warnings. Anything that is fine is dropped from the body
     * entirely — it is still in the JSON, for a dashboard that wants every reading.
     *
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function compose(array $sections): array
    {
        $previous = $this->previousMetrics();

        foreach ($sections as $key => $section) {
            $was = $previous[$key] ?? null;
            $sections[$key]['previous'] = $was;
            // Strictly greater. Equal is not "worse", and a first run has nothing to compare
            // against — calling that a deterioration would make every fresh installation shout.
            $sections[$key]['worse'] = $was !== null && (int) $section['metric'] > $was;
        }

        $notable = array_values(array_filter(
            $sections,
            static fn (array $section): bool => $section['severity'] !== self::OK,
        ));

        usort($notable, static function (array $left, array $right): int {
            $rank = static fn (array $section): int => match (true) {
                $section['severity'] === self::ALARM && $section['worse'] => 0,
                $section['severity'] === self::ALARM => 1,
                $section['worse'] => 2,
                default => 3,
            };

            return $rank($left) <=> $rank($right);
        });

        $blind = array_values(array_filter(array_map(
            static fn (array $section): ?string => $section['blind'],
            $sections,
        )));

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'window_hours' => self::WINDOW_HOURS,
            'app_version' => $this->setting('ops.app_version'),
            'severity' => $notable === [] ? self::OK : (string) $notable[0]['severity'],
            'title' => $this->title($notable),
            'body' => $this->body($notable, $blind),
            'notable' => $notable,
            'blind_spots' => $blind,
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $notable
     */
    private function title(array $notable): string
    {
        if ($notable === []) {
            return 'Daily digest — nothing to report';
        }

        $alarms = count(array_filter($notable, static fn (array $s): bool => $s['severity'] === self::ALARM));

        if ($alarms > 0) {
            return sprintf('Daily digest — %d thing(s) need attention today', $alarms);
        }

        return sprintf('Daily digest — %d thing(s) to look at this week', count($notable));
    }

    /**
     * @param  list<array<string, mixed>>  $notable
     * @param  list<string>  $blind
     */
    private function body(array $notable, array $blind): string
    {
        if ($notable === []) {
            // One line, and it still arrives. See rule 3 in the class note.
            return 'Nothing to report.';
        }

        $lines = [];

        foreach ($notable as $section) {
            $lines[] = sprintf(
                '%s%s: %s',
                (string) $section['label'],
                $section['worse'] ? sprintf(' (worse — was %d)', (int) $section['previous']) : '',
                (string) $section['headline'],
            );

            foreach ($section['lines'] as $line) {
                $lines[] = '  · '.(string) $line;
            }
        }

        foreach ($blind as $sentence) {
            $lines[] = 'Not measured: '.$sentence;
        }

        return implode("\n", $lines);
    }

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    /**
     * Send it to whoever may read the logs (section 10.3).
     *
     * `system_health.view_logs`, not "every Super Admin": the audience is the people whose job this
     * is, and on a system with one Super Admin who never signs in that distinction is the difference
     * between a digest that is read and one that accumulates.
     *
     * **The payload carries counts and labels, never a row of business data** (section 10.3). A route
     * name, an exception class and a total are safe in a bell row and in a mail; a collaborator's
     * name, a balance or the SQL a slow query ran are not, and a notification is the one artefact
     * here that leaves the system.
     *
     * A missing registry key is logged and printed, not thrown. The event belongs to the notification
     * registry, and until it is declared there this command still has to run — a digest that fatals
     * because its event key is not registered takes the whole scheduled entry down with it.
     *
     * @param  array<string, mixed>  $digest
     */
    private function deliver(array $digest): void
    {
        $key = 'ops.digest';

        if (! NotificationRegistry::has($key)) {
            Log::warning('ops:digest could not be delivered: the notification event is not registered.', [
                'event' => $key,
                'severity' => $digest['severity'],
                'title' => $digest['title'],
            ]);

            $this->warn(sprintf(
                'ops:digest — the "%s" notification event is not registered, so nothing was delivered. '
                .'The digest above was written to the log instead.',
                $key,
            ));

            return;
        }

        try {
            $result = $this->notifications->dispatchToPermission($key, 'system_health.view_logs', [
                'title' => $digest['title'],
                'body' => $digest['body'],
                'severity' => $digest['severity'],
                'window_hours' => $digest['window_hours'],
                'app_version' => $digest['app_version'],
                // Counts only. Deliberately not `sections`, which carries remediation prose and
                // route names that a future edit could grow into something with a row in it.
                'metrics' => array_map(
                    static fn (array $section): int => (int) $section['metric'],
                    $digest['sections'],
                ),
            ]);

            $this->info('ops:digest — '.$result->describe());

            if (! $result->reachedAnybody()) {
                // The failure mode GL-44 exists for: a digest that was assembled, dispatched and
                // delivered to nobody at all looks exactly like a digest that worked.
                $this->warn('ops:digest — it reached nobody. Check that somebody holds system_health.view_logs.');
            }
        } catch (Throwable $exception) {
            // Exit code stays 0. See the class note: failing here would silence the heartbeats.
            Log::error('ops:digest could not be delivered.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->error('ops:digest — delivery failed: '.$exception::class.'. The digest is in the log.');
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     */
    private function remember(array $sections): void
    {
        try {
            Cache::put(
                self::METRICS_KEY,
                array_map(static fn (array $section): int => (int) $section['metric'], $sections),
                Carbon::now()->addDays(8),
            );
        } catch (Throwable) {
            // No comparison tomorrow, and nothing worse than that. Every section still reports its
            // own figure; it simply cannot say whether it got worse.
        }
    }

    /**
     * @return array<string, int>
     */
    private function previousMetrics(): array
    {
        try {
            $stored = Cache::get(self::METRICS_KEY);
        } catch (Throwable) {
            return [];
        }

        return is_array($stored) ? array_map('intval', $stored) : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Console output
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $digest
     */
    private function renderToConsole(array $digest): void
    {
        $this->newLine();
        $this->line(' <options=bold>'.(string) $digest['title'].'</>');
        $this->newLine();

        foreach (preg_split('/\R/', (string) $digest['body']) ?: [] as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();

        /** @var array<string, array<string, mixed>> $sections */
        $sections = $digest['sections'];

        $quiet = array_values(array_filter(
            $sections,
            static fn (array $section): bool => $section['severity'] === self::OK,
        ));

        if ($quiet !== []) {
            // Printed on the console and NOT in the message: somebody who ran this by hand wants to
            // know the section ran, and a recipient does not.
            $this->line(sprintf(
                '  <fg=gray>fine: %s</>',
                implode(', ', array_map(static fn (array $s): string => (string) $s['label'], $quiet)),
            ));
        }

        foreach ($digest['notable'] as $section) {
            if ($section['remediation'] !== null) {
                $this->line(sprintf('  <fg=yellow>%s</> → %s', (string) $section['label'], (string) $section['remediation']));
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Shared readings
    |--------------------------------------------------------------------------
    */

    /**
     * Every line of the last day's daily logs.
     *
     * Today's file and yesterday's, because a 24-hour window straddles midnight. Streamed one line
     * at a time: a busy installation's daily log runs to hundreds of megabytes and a digest that
     * loaded it into an array would be the heaviest thing on the box at 07:00.
     *
     * The window is not filtered per line. A stack trace spans lines and only its first line carries
     * a timestamp, so a per-line date filter would drop the trace and keep counting its fragments —
     * two files is a close enough window for counting, and an honest one.
     *
     * @return Generator<int, string>
     */
    private function recentLogLines(): Generator
    {
        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return;
        }

        foreach ([Carbon::yesterday(), Carbon::today()] as $day) {
            $path = $directory.'/laravel-'.$day->toDateString().'.log';

            if (! File::exists($path)) {
                continue;
            }

            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                // A log locked by Apache on Windows. Not a reason to fail the digest.
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * The `self::TOP_N` most frequent labels, as printable lines.
     *
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function top(array $counts): array
    {
        arsort($counts);

        $lines = [];

        foreach (array_slice($counts, 0, self::TOP_N, true) as $label => $count) {
            $lines[] = sprintf('%s × %d', (string) $label, $count);
        }

        $remaining = count($counts) - count($lines);

        if ($remaining > 0) {
            $lines[] = sprintf('and %d more', $remaining);
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        string $label,
        string $severity,
        int $metric,
        string $headline,
        array $lines = [],
        ?string $remediation = null,
        ?string $blind = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'severity' => $severity,
            'metric' => $metric,
            'headline' => $headline,
            'lines' => $lines,
            'remediation' => $remediation,
            'blind' => $blind,
            // Filled by compose(); declared here so no reader has to test for the key.
            'previous' => null,
            'worse' => false,
        ];
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        try {
            return setting($key, $default);
        } catch (Throwable) {
            // The digest must still assemble on a system whose settings store is unreachable — that
            // system is precisely the one somebody needs a digest about.
            return $default;
        }
    }
}
