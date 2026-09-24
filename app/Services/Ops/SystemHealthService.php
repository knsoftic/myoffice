<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\BackupVerificationStatus;
use App\Enums\IntegrityCheckSuite;
use App\Models\Ops\IntegrityCheckRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * What this installation looks like right now, probe by probe (phase-24-25 §6.2, §8.4).
 *
 * **One health implementation, and this is it (HD-3).** The `/health` endpoint, `ops:health`,
 * `ops:check-heartbeats`, the admin health screen and Phase 2's `SystemHealthWidget` all read this
 * service. The failure mode it exists to prevent is the ordinary one: four places that each compute
 * "is the queue alive" slightly differently, so the screen is green, the endpoint is red, and the
 * argument about which to believe happens during the outage rather than before it.
 *
 * **Every probe answers in scalars — counts, ages, booleans and versions — and nothing else.** No
 * row, no name, no email, no balance, no absolute path. The reason is that this output leaves the
 * building: `HealthController` hands it to an unauthenticated monitoring agent holding a token. A
 * probe that returned "last backup: C:\xampp\htdocs\my office\storage\app\backups\my_office-…zip"
 * would be telling a stranger the database name, the operating system and the directory layout, in
 * a response nobody reads because it is green.
 *
 * **A probe that cannot be measured is amber, never green** (§8.4). `null` and `0` are different
 * claims: "the queue has been idle for zero minutes" and "nobody could ask the queue" must not
 * render the same, because the second one is how a dead monitoring path looks from the outside.
 *
 * **Nothing here throws.** A health service that raises when the database is down cannot report
 * that the database is down — so every probe catches `Throwable`, and even the settings reads go
 * through {@see self::config()}, which falls back to the registry default when the settings table
 * cannot be reached. A thrown exception's *message* is never surfaced either: a PDO connection
 * error carries the host, the user and sometimes the password, so only the exception class name is
 * reported.
 *
 * Probe result shape (one array per probe, the DTO `ProbeResult` will wrap this unchanged):
 *
 *   ['key', 'label', 'group', 'status', 'value', 'unit', 'threshold',
 *    'measured_at', 'duration_ms', 'detail', 'remediation']
 *
 * `detail` and `remediation` are prose for a person who has already authenticated, and
 * {@see self::publicPayload()} strips them — see the note on that method.
 */
final class SystemHealthService
{
    /** Everything this probe checked held. */
    public const STATUS_OK = 'ok';

    /** Nothing is broken this second, and something here is how it breaks tonight. */
    public const STATUS_DEGRADED = 'degraded';

    /** A thing the application depends on is not working. `/health` answers 503. */
    public const STATUS_FAILED = 'failed';

    /**
     * The cache key `ops:heartbeat` stamps every minute.
     *
     * Declared here rather than in the command because three separate pieces of code write or read
     * it — the stamp, the probe and `ops:check-heartbeats` — and a heartbeat whose key was spelled
     * two ways is a monitor that reports a stalled scheduler for ever while the scheduler runs.
     */
    public const SCHEDULER_HEARTBEAT_KEY = 'ops.scheduler_heartbeat';

    /** The cache key `QueuePingJob` stamps — written by a worker, so it proves a worker ran. */
    public const QUEUE_HEARTBEAT_KEY = 'ops.queue_heartbeat';

    /** The minute counter `ops:heartbeat` keeps, so the queue ping goes out every fifth run. */
    public const HEARTBEAT_TICK_KEY = 'ops.heartbeat.tick';

    /** One queue ping per this many scheduler ticks (§6.6) — five minutes of default cadence. */
    public const QUEUE_PING_EVERY = 5;

    /**
     * How stale a financial proof may be before the money probe goes amber.
     *
     * `integrity:verify --suite=all` runs nightly at 02:15 (§10.4), so 36 hours is one missed night
     * plus a margin. Tighter than that and every deploy window produces a false amber; looser and a
     * sweep that stopped running takes three days to become visible.
     */
    private const FINANCIAL_PROOF_MAX_HOURS = 36;

    /**
     * Probes whose cost is only paid on `--deep`.
     *
     * The endpoint is polled every minute by a monitor. A filesystem scan of `database/migrations`
     * and a `disk_free_space` call on every poll is work the machine does for nobody; before a
     * deploy (§6.11 step 1) it is exactly what somebody wants to know.
     */
    private const DEEP_ONLY = ['migrations', 'disk_space'];

    /**
     * The catalogue: every probe, in the order a person should read them.
     *
     * The screen renders this before any measurement happens, so a slow probe shows a skeleton in
     * its own card rather than delaying the page (§8.4).
     *
     * @return array<string, array{label: string, group: string, description: string, deep: bool}>
     */
    public function probes(): array
    {
        return [
            'database' => [
                'label' => 'Database',
                'group' => 'database',
                'description' => 'The application can reach the database and get an answer out of it.',
                'deep' => false,
            ],
            'cache' => [
                'label' => 'Cache store',
                'group' => 'runtime',
                'description' => 'A value written to the cache can be read back. Settings, permissions and the sidebar all live here.',
                'deep' => false,
            ],
            'queue' => [
                'label' => 'Queue worker',
                'group' => 'queue',
                'description' => 'A worker stamped the queue heartbeat recently enough.',
                'deep' => false,
            ],
            'scheduler' => [
                'label' => 'Scheduler',
                'group' => 'scheduler',
                'description' => 'The minute scheduler stamped its heartbeat recently enough.',
                'deep' => false,
            ],
            'failed_jobs' => [
                'label' => 'Failed jobs',
                'group' => 'queue',
                'description' => 'How many jobs are sitting in failed_jobs, against the alert threshold.',
                'deep' => false,
            ],
            'storage' => [
                'label' => 'Storage',
                'group' => 'storage',
                'description' => 'Every directory the application writes to is writable.',
                'deep' => false,
            ],
            'backup' => [
                'label' => 'Backups',
                'group' => 'backups',
                'description' => 'The age of the newest completed database archive, and whether it has been verified.',
                'deep' => false,
            ],
            'integrity' => [
                'label' => 'Financial proofs',
                'group' => 'money',
                'description' => 'The last integrity run for each financial suite: constraints and wallet reconciliation.',
                'deep' => false,
            ],
            'runtime' => [
                'label' => 'Runtime',
                'group' => 'runtime',
                'description' => 'Deployed version, PHP and framework versions, environment and the debug flag.',
                'deep' => false,
            ],
            'migrations' => [
                'label' => 'Pending migrations',
                'group' => 'database',
                'description' => 'Migration files on disk that the database has not run.',
                'deep' => true,
            ],
            'disk_space' => [
                'label' => 'Disk space',
                'group' => 'storage',
                'description' => 'Free space on the volume the application writes to.',
                'deep' => true,
            ],
        ];
    }

    /**
     * Every probe, with one verdict over all of them.
     *
     * @return array{
     *     status: string,
     *     generated_at: string,
     *     deep: bool,
     *     app_version: string|null,
     *     counts: array{ok: int, degraded: int, failed: int},
     *     checks: array<string, array<string, mixed>>
     * }
     */
    public function snapshot(bool $deep = false): array
    {
        $checks = [];

        foreach (array_keys($this->probes()) as $key) {
            if (! $deep && in_array($key, self::DEEP_ONLY, true)) {
                continue;
            }

            $checks[$key] = $this->probe($key, $deep);
        }

        $counts = [self::STATUS_OK => 0, self::STATUS_DEGRADED => 0, self::STATUS_FAILED => 0];

        foreach ($checks as $check) {
            $counts[$check['status']]++;
        }

        return [
            'status' => $this->worst(array_column($checks, 'status')),
            'generated_at' => Carbon::now()->toIso8601String(),
            'deep' => $deep,
            'app_version' => $this->appVersion(),
            'counts' => $counts,
            'checks' => $checks,
        ];
    }

    /**
     * The body `/health` returns, and deliberately a narrower thing than `snapshot()`.
     *
     * **Prose is dropped here, not sanitised here.** `detail` and `remediation` are sentences, and a
     * sentence is the field somebody later interpolates a filename or a collaborator's name into
     * without thinking about who reads it. The public body is a fixed set of scalars per check, so
     * there is no field on this path that *could* carry a path, a row or a name — which is a
     * property somebody can verify by reading this method, rather than a promise about every future
     * edit to every probe.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(bool $deep = false): array
    {
        $snapshot = $this->snapshot($deep);

        $checks = [];

        foreach ($snapshot['checks'] as $key => $check) {
            $checks[$key] = [
                'status' => $check['status'],
                'value' => $check['value'],
                'unit' => $check['unit'],
                'threshold' => $check['threshold'],
            ];
        }

        return [
            'status' => $snapshot['status'],
            'time' => $snapshot['generated_at'],
            'version' => $snapshot['app_version'],
            'checks' => $checks,
        ];
    }

    /**
     * Run one probe.
     *
     * @return array<string, mixed>
     */
    public function probe(string $key, bool $deep = false): array
    {
        $definition = $this->probes()[$key] ?? null;

        if ($definition === null) {
            // A typo in a probe name is a programmer error and must not read as a healthy system.
            throw new InvalidArgumentException(sprintf('Unknown health probe [%s].', $key));
        }

        $started = microtime(true);

        try {
            $result = match ($key) {
                'database' => $this->probeDatabase(),
                'cache' => $this->probeCache(),
                'queue' => $this->probeHeartbeat(
                    self::QUEUE_HEARTBEAT_KEY,
                    (int) $this->config('ops.queue_heartbeat_max_minutes', 10),
                    'No worker has stamped the queue heartbeat yet.',
                    'Start the worker: php artisan queue:work --queue=high,default,low',
                ),
                'scheduler' => $this->probeHeartbeat(
                    self::SCHEDULER_HEARTBEAT_KEY,
                    (int) $this->config('ops.scheduler_heartbeat_max_minutes', 5),
                    'The scheduler has never stamped its heartbeat.',
                    'Check the minute task runs: php artisan schedule:run',
                ),
                'failed_jobs' => $this->probeFailedJobs(),
                'storage' => $this->probeStorage(),
                'backup' => $this->probeBackup(),
                'integrity' => $this->probeIntegrity(),
                'runtime' => $this->probeRuntime(),
                'migrations' => $this->probeMigrations(),
                'disk_space' => $this->probeDiskSpace(),
                default => $this->unmeasured('not implemented'),
            };
        } catch (Throwable $exception) {
            /*
            | Amber, and only the class name (§8.4). The message of a database, filesystem or HTTP
            | exception routinely carries the host, the user, a full path or a credential, and this
            | array is rendered on a screen and — after publicPayload() — shipped off the machine.
            */
            $result = $this->unmeasured(class_basename($exception));
        }

        return array_merge([
            'key' => $key,
            'label' => $definition['label'],
            'group' => $definition['group'],
            'value' => null,
            'unit' => null,
            'threshold' => null,
            'detail' => null,
            'remediation' => null,
        ], $result, [
            'measured_at' => Carbon::now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'deep' => $definition['deep'],
        ]);
    }

    /**
     * The worst of several statuses — how nine probes become one word.
     *
     * @param  iterable<string>  $statuses
     */
    public function worst(iterable $statuses): string
    {
        $rank = [self::STATUS_OK => 0, self::STATUS_DEGRADED => 1, self::STATUS_FAILED => 2];
        $worst = self::STATUS_OK;

        foreach ($statuses as $status) {
            if (($rank[$status] ?? 2) > $rank[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * The process exit code for a status: 0 ok, 1 degraded, 2 failed (§6.6).
     *
     * The same three-way split `IntegrityCheckStatus::exitCode()` uses, for the same reason: a
     * monitoring agent that treats every non-zero alike cannot page on a failure while letting a
     * warning through.
     */
    public function exitCodeFor(string $status): int
    {
        return match ($status) {
            self::STATUS_OK => 0,
            self::STATUS_DEGRADED => 1,
            default => 2,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Probes
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function probeDatabase(): array
    {
        $started = microtime(true);

        try {
            DB::connection()->select('select 1 as ok');
        } catch (Throwable $exception) {
            return [
                'status' => self::STATUS_FAILED,
                'value' => false,
                'unit' => 'reachable',
                'detail' => 'The database refused or dropped the connection ('.class_basename($exception).').',
                'remediation' => 'Check the database service and the credentials in .env, then: php artisan db:monitor',
            ];
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        return [
            // The connection *name*, never the database name: the schema name is a fact about the
            // installation that an unauthenticated caller has no use for.
            'status' => self::STATUS_OK,
            'value' => $ms,
            'unit' => 'ms',
            'detail' => sprintf('Answered a trivial query in %dms on the "%s" driver.', $ms, DB::connection()->getDriverName()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeCache(): array
    {
        $key = 'ops.health.cache-probe';
        $value = (string) Str::ulid();

        try {
            Cache::put($key, $value, 30);
            $read = Cache::get($key);
            Cache::forget($key);
        } catch (Throwable $exception) {
            return [
                'status' => self::STATUS_FAILED,
                'value' => false,
                'unit' => 'reachable',
                'detail' => 'The cache store could not be written ('.class_basename($exception).').',
                'remediation' => 'Check the cache driver; on the database driver, that the `cache` table exists.',
            ];
        }

        // A store that accepts a write and returns something else is worse than one that refuses:
        // settings, permissions and the sidebar are all read through it, so the application would
        // be serving stale authorisation data and reporting itself healthy.
        if ($read !== $value) {
            return [
                'status' => self::STATUS_FAILED,
                'value' => false,
                'unit' => 'reachable',
                'detail' => 'The cache returned a different value from the one just written.',
                'remediation' => 'php artisan optimize:clear, then check the cache driver.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'value' => true,
            'unit' => 'reachable',
            'detail' => sprintf('Wrote and read back a value on the "%s" store.', (string) config('cache.default')),
        ];
    }

    /**
     * One heartbeat, judged against its own ceiling.
     *
     * **Never stamped and stamped-too-long-ago are different verdicts.** A fresh installation whose
     * worker has not started yet is amber: reporting 503 there would teach the first person who
     * looks that `/health` is noisy, and a monitor people have learned to ignore is not a monitor.
     * A heartbeat that *was* arriving and stopped is red, because something that used to work does
     * not any more.
     *
     * @return array<string, mixed>
     */
    private function probeHeartbeat(string $cacheKey, int $maxMinutes, string $neverDetail, string $remediation): array
    {
        $stamp = Cache::get($cacheKey);

        if ($stamp === null) {
            return [
                'status' => self::STATUS_DEGRADED,
                'value' => null,
                'unit' => 'minutes',
                'threshold' => $maxMinutes,
                'detail' => $neverDetail,
                'remediation' => $remediation,
            ];
        }

        $at = $stamp instanceof Carbon ? $stamp : Carbon::createFromTimestamp((int) $stamp);
        $ageMinutes = (int) floor($at->diffInSeconds(Carbon::now(), absolute: true) / 60);

        return [
            'status' => $ageMinutes > $maxMinutes ? self::STATUS_FAILED : self::STATUS_OK,
            'value' => $ageMinutes,
            'unit' => 'minutes',
            'threshold' => $maxMinutes,
            'detail' => sprintf('Last stamped %d minute(s) ago; the ceiling is %d.', $ageMinutes, $maxMinutes),
            'remediation' => $ageMinutes > $maxMinutes ? $remediation : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeFailedJobs(): array
    {
        $threshold = (int) $this->config('ops.failed_jobs_alert_threshold', 10);

        if (! Schema::hasTable('failed_jobs')) {
            return $this->unmeasured('the failed_jobs table does not exist') + ['threshold' => $threshold];
        }

        $count = (int) DB::table('failed_jobs')->count();

        return [
            'status' => match (true) {
                $count >= $threshold => self::STATUS_FAILED,
                $count > 0 => self::STATUS_DEGRADED,
                default => self::STATUS_OK,
            },
            'value' => $count,
            'unit' => 'jobs',
            'threshold' => $threshold,
            'detail' => sprintf('%d failed job(s); the alert threshold is %d.', $count, $threshold),
            // Never `queue:flush` before reading them: a flushed commission job is lost work (§6.9.5).
            'remediation' => $count > 0 ? 'php artisan queue:failed, then queue:retry <id> — read them before flushing anything.' : null,
        ];
    }

    /**
     * Every directory the application writes to, as a count and a boolean.
     *
     * **Relative labels, never absolute paths.** "storage/logs" tells an operator which directory;
     * "C:\xampp\htdocs\my office\storage\logs" tells a stranger the drive layout, the web root and
     * the fact that the path contains a space.
     *
     * @return array<string, mixed>
     */
    private function probeStorage(): array
    {
        $paths = [
            'storage/app/private' => storage_path('app/private'),
            'storage/app/backups' => storage_path('app/backups'),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $unwritable = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $unwritable[] = $label;
            }
        }

        if ($unwritable === []) {
            return [
                'status' => self::STATUS_OK,
                'value' => count($paths),
                'unit' => 'writable directories',
                'threshold' => count($paths),
                'detail' => sprintf('All %d write targets are present and writable.', count($paths)),
            ];
        }

        return [
            'status' => self::STATUS_FAILED,
            'value' => count($paths) - count($unwritable),
            'unit' => 'writable directories',
            'threshold' => count($paths),
            'detail' => 'Not writable: '.implode(', ', $unwritable).'.',
            'remediation' => 'Restore write permission for the web server user (§6.9.4), then: php artisan optimize:clear',
        ];
    }

    /**
     * The newest completed database archive: how old, and whether anybody has proved it restores.
     *
     * **The rows are read with the query builder, and the values compared through the enums.** The
     * two halves of that sentence protect against different things and only one of them is about
     * this slice landing late. The *table* may genuinely be absent — on a fresh install, before
     * `migrate` has run — and a health service that fatals on a missing table is precisely the
     * outage it was written to report on, so `Schema::hasTable()` guards it and no model is
     * touched. The *enums* are a different matter: a pure backed enum is a class with no
     * dependencies, always loadable, and comparing against hand-typed literals instead is how this
     * probe spent a round reporting `degraded` for ever — `'verified'` is not a case of
     * {@see BackupVerificationStatus} (its values are `unverified`, `checksum_ok`, `restore_ok`,
     * `failed`), so the comparison was false on every row, `worst()` pinned the whole snapshot at
     * amber and `ops:health` exited 1 on a perfectly healthy machine. Three literals matched and
     * the fourth drifted, which is golden rule 8 failing exactly as written.
     *
     * @return array<string, mixed>
     */
    private function probeBackup(): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return $this->unmeasured('the backup_runs table does not exist yet');
        }

        $latest = DB::table('backup_runs')
            ->whereIn('type', $this->databaseArchiveTypes())
            ->where('status', BackupStatus::Completed->value)
            ->whereNull('file_pruned_at')
            ->orderByDesc('finished_at')
            ->first(['finished_at', 'verification_status', 'verified_at']);

        $maxHours = $this->backupMaxAgeHours();

        if ($latest === null || $latest->finished_at === null) {
            return [
                'status' => self::STATUS_FAILED,
                'value' => null,
                'unit' => 'hours',
                'threshold' => $maxHours,
                'detail' => 'No completed database archive is on record.',
                'remediation' => 'php artisan backup:run --type=database --reason="first archive"',
            ];
        }

        $ageHours = (int) floor(
            Carbon::parse((string) $latest->finished_at)->diffInMinutes(Carbon::now(), absolute: true) / 60
        );

        /*
        | `checksumProved()` and not `satisfiesGoLive()`, because the two answer different
        | questions and this probe is asking the narrower one: has anything at all been proved
        | about the file on disk. `ChecksumOk` and `RestoreOk` both say yes. Gating this line on
        | `satisfiesGoLive()` (true for `RestoreOk` alone) would paint every installation amber
        | until the weekly deep verification of §6.10.4 has run once — a permanent amber that
        | teaches people to ignore the card, which is how the one that matters gets missed.
        | Whether the restore has been *proved* is §6.13's question and the checklist asks it there.
        |
        | An unparseable value — a row written before the enum existed, or by hand — is `null` and
        | therefore not proved. Unrecognised must never read as verified.
        */
        $verification = BackupVerificationStatus::tryFrom((string) ($latest->verification_status ?? ''));
        $verified = $verification?->checksumProved() ?? false;

        // Order matters: an old archive is the worse finding, so it wins the verdict. An unverified
        // recent one is amber — it probably restores, and "probably" is exactly what §6.10.4 exists
        // to stop anybody relying on.
        $status = match (true) {
            $maxHours !== null && $ageHours > $maxHours => self::STATUS_FAILED,
            ! $verified => self::STATUS_DEGRADED,
            default => self::STATUS_OK,
        };

        return [
            'status' => $status,
            'value' => $ageHours,
            'unit' => 'hours',
            'threshold' => $maxHours,
            'detail' => sprintf(
                // The enum's own label, so the card says "Restore proved" rather than flattening
                // the four states of HD-6 back into the boolean the enum exists to replace.
                'Newest database archive is %d hour(s) old; verification: %s.',
                $ageHours,
                $verification?->label() ?? 'unrecognised',
            ),
            'remediation' => $status === self::STATUS_OK
                ? null
                : 'php artisan backup:run --type=database, then backup:verify --latest',
        ];
    }

    /**
     * The last run of each financial suite.
     *
     * **Read, never recomputed (INV-26).** Whether the wallets reconcile is decided by
     * `collaborators:reconcile-wallets` and recorded by `IntegrityCheckService`; this probe reports
     * what that run said. A health screen that re-derived the answer would be a second opinion
     * about the money, and two opinions that disagree are worse than one that is occasionally old.
     *
     * @return array<string, mixed>
     */
    private function probeIntegrity(): array
    {
        if (! Schema::hasTable('integrity_check_runs')) {
            return $this->unmeasured('the integrity_check_runs table does not exist yet');
        }

        $financial = array_filter(
            IntegrityCheckSuite::cases(),
            static fn (IntegrityCheckSuite $suite): bool => $suite->isFinancial(),
        );

        $oldestHours = null;
        $blocking = 0;
        $missing = 0;

        foreach ($financial as $suite) {
            /** @var IntegrityCheckRun|null $run */
            $run = IntegrityCheckRun::query()
                ->forSuite($suite)
                ->orderByDesc('id')
                ->first();

            if ($run === null) {
                $missing++;

                continue;
            }

            if ($run->blocksGoLive()) {
                $blocking++;
            }

            $hours = (int) floor(
                ($run->finished_at ?? $run->created_at)->diffInMinutes(Carbon::now(), absolute: true) / 60
            );

            $oldestHours = $oldestHours === null ? $hours : max($oldestHours, $hours);
        }

        $status = match (true) {
            $blocking > 0 => self::STATUS_FAILED,
            $missing > 0 => self::STATUS_DEGRADED,
            $oldestHours !== null && $oldestHours > self::FINANCIAL_PROOF_MAX_HOURS => self::STATUS_DEGRADED,
            default => self::STATUS_OK,
        };

        return [
            'status' => $status,
            'value' => $oldestHours,
            'unit' => 'hours',
            'threshold' => self::FINANCIAL_PROOF_MAX_HOURS,
            'detail' => sprintf(
                '%d financial suite(s) unproven, %d blocking; oldest proof %s.',
                $missing,
                $blocking,
                $oldestHours === null ? 'n/a' : $oldestHours.'h',
            ),
            'remediation' => $status === self::STATUS_OK
                ? null
                : 'php artisan integrity:verify --suite=constraints && php artisan integrity:verify --suite=wallet',
        ];
    }

    /**
     * Versions and flags — the three questions asked first after every deploy.
     *
     * @return array<string, mixed>
     */
    private function probeRuntime(): array
    {
        $environment = (string) config('app.env', 'production');
        $debug = (bool) config('app.debug', false);
        $version = $this->appVersion();

        /*
        | APP_DEBUG on outside local is a real finding (SEC-02 fails on it), and it is amber here
        | rather than red on purpose: red makes /health answer 503, which takes the site out of a
        | load balancer over a configuration flag while the application is serving every request
        | perfectly. The security audit is where this blocks a go-live; this line is where somebody
        | notices it at 9 a.m.
        */
        $status = $debug && $environment !== 'local' ? self::STATUS_DEGRADED : self::STATUS_OK;

        return [
            'status' => $status,
            'value' => $version,
            'unit' => 'version',
            'detail' => sprintf(
                'app %s · PHP %s · Laravel %s · env %s · debug %s',
                $version ?? 'unstamped',
                PHP_VERSION,
                app()->version(),
                $environment,
                $debug ? 'on' : 'off',
            ),
            'remediation' => $status === self::STATUS_OK
                ? null
                : 'Set APP_DEBUG=false in .env and run: php artisan config:clear',
        ];
    }

    /**
     * Migration files on disk that the `migrations` table has never seen (deep only).
     *
     * @return array<string, mixed>
     */
    private function probeMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return $this->unmeasured('the migrations table does not exist');
        }

        $ran = DB::table('migrations')->pluck('migration')->all();

        $files = glob(database_path('migrations').DIRECTORY_SEPARATOR.'*.php');
        $files = $files === false ? [] : $files;

        $pending = 0;

        foreach ($files as $file) {
            if (! in_array(basename($file, '.php'), $ran, true)) {
                $pending++;
            }
        }

        return [
            // Amber, not red: an application serving requests with a pending migration is a deploy
            // that stopped half-way, which somebody must finish — not an outage in progress.
            'status' => $pending > 0 ? self::STATUS_DEGRADED : self::STATUS_OK,
            'value' => $pending,
            'unit' => 'pending migrations',
            'threshold' => 0,
            'detail' => sprintf('%d migration file(s) have not been run.', $pending),
            'remediation' => $pending > 0 ? 'php artisan migrate --force' : null,
        ];
    }

    /**
     * Free space where the application writes (deep only).
     *
     * @return array<string, mixed>
     */
    private function probeDiskSpace(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false || $total <= 0) {
            return $this->unmeasured('the filesystem did not report its size');
        }

        $freePercent = (int) round($free / $total * 100);

        return [
            'status' => match (true) {
                $freePercent < 5 => self::STATUS_FAILED,
                $freePercent < 15 => self::STATUS_DEGRADED,
                default => self::STATUS_OK,
            },
            'value' => $freePercent,
            'unit' => 'percent free',
            'threshold' => 15,
            'detail' => sprintf('%d%% of the volume is free.', $freePercent),
            'remediation' => $freePercent < 15
                ? 'php artisan backup:prune --dry-run, then prune; check storage/logs retention.'
                : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The amber "could not be measured" verdict of §8.4.
     *
     * @return array<string, mixed>
     */
    private function unmeasured(string $why): array
    {
        return [
            'status' => self::STATUS_DEGRADED,
            'value' => null,
            'detail' => 'Could not be measured: '.$why.'.',
        ];
    }

    /**
     * The `backup_runs.type` values whose archive actually contains a dump.
     *
     * **A `full` archive contains the database, and this probe used to ignore it.** The literal it
     * replaced was `type = 'database'`, while §6.10.3's minimum-copies rule, the go-live gate and
     * `BackupRun::scopeUsableDatabaseArchives()` all count with `BackupType::includesDatabase()`.
     * On an installation whose schedule writes `full` archives that disagreement showed as a red
     * backup card every morning over a shelf that retention and the checklist both considered
     * healthy — three opinions about what a database backup is, and the one an operator reads
     * daily was the wrong one. Deriving the list from the enum means a fourth type is counted
     * correctly or not at all, never counted wrongly.
     *
     * @return list<string>
     */
    private function databaseArchiveTypes(): array
    {
        return array_values(array_map(
            static fn (BackupType $type): string => $type->value,
            array_filter(
                BackupType::cases(),
                static fn (BackupType $type): bool => $type->includesDatabase(),
            ),
        ));
    }

    /**
     * How old the newest database archive may be before the backup probe goes red.
     *
     * Derived from the configured schedule rather than fixed, because "36 hours old" is a failure
     * on a daily schedule and perfectly normal on a weekly one — and a threshold that ignores the
     * schedule is a threshold somebody switches the probe off to silence. `off` returns null: a
     * backup nobody scheduled cannot be late.
     */
    private function backupMaxAgeHours(): ?int
    {
        return match ((string) $this->config('backup.database_schedule', 'daily')) {
            'off' => null,
            'twice_daily' => 18,
            'weekly' => 9 * 24,
            default => 36,
        };
    }

    private function appVersion(): ?string
    {
        $version = $this->config('ops.app_version', null);

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * A settings read that survives the database being down.
     *
     * Without this, every probe after `database` would throw the moment the connection failed, and
     * the one report that had to survive a dead database would be the report that could not be
     * produced. The fallback is the caller's default — the same value the registry declares.
     */
    private function config(string $key, mixed $default): mixed
    {
        try {
            return setting($key, $default) ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
