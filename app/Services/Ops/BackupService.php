<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Enums\BackupType;
use App\Enums\BackupVerificationStatus;
use App\Events\Ops\BackupCompleted;
use App\Events\Ops\BackupFailed;
use App\Events\Ops\BackupStarted;
use App\Jobs\Ops\CopyBackupOffsite;
use App\Jobs\Ops\VerifyBackupJob;
use App\Models\Ops\BackupRun;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Takes a backup and records that it was taken (phase-24-25 §6.2, §6.10).
 *
 * **The row is written before the dump starts, not after it finishes.** A process killed mid-dump —
 * a rebooted server, an out-of-memory kill, an operator who closed the console — then leaves
 * evidence rather than silence, and "the nightly backup produced no row at all" is the failure this
 * table exists to make visible. A run that never reached `completed` is a run somebody has to look
 * at; a night with no row is a night nobody knows about.
 *
 * **Two dumps of the same database are never started at once.** The guard is a cache lock
 * (`ops.backup.running`, TTL two hours), because a check against the table is a check-then-act race
 * and the thing at stake is two `mysqldump` processes competing for the same rows while fees are
 * being collected.
 *
 * **The database password is never on a command line.** It goes into a `--defaults-extra-file`
 * written 0600 into the run's private workspace and deleted in a `finally`. A password on the
 * command line is readable by every other process on the machine through the process list, and it
 * lands in shell history the first time an operator reproduces the command by hand.
 *
 * **`--single-transaction` is mandatory.** Without it `mysqldump` locks tables, and a lock held for
 * the length of a dump is a lock held across the counter taking a fee payment.
 *
 * **`jobs` and `failed_jobs` are not excluded** (they are absent from `backup.excluded_tables` on
 * purpose): a queued commission job is money that has been earned and not yet written, and a restore
 * that silently drops it is the one failure mode a restore must not have.
 *
 * **`.env` goes into an archive only when `backup.encrypt_archives` *and* `backup.include_env` are
 * both on** (HD-8). An `.env` holds the application key, the database password and the mail
 * credentials, so an unencrypted archive carrying one is not a backup of the system, it *is* the
 * system — including the offsite copy, including the copy on somebody's laptop.
 * `backup_runs.includes_env` records what was actually done rather than what was configured.
 *
 * **`backup.archive_password` is read once, used once and never written anywhere.** It is not
 * logged, not echoed, not put in a notification and not stored in the manifest; the only place it
 * appears is `ZipArchive::setPassword()`, and {@see scrub()} removes it from any exception message
 * before that message reaches the `error_message` column.
 *
 * **Nothing here deletes anything.** Pruning belongs to {@see BackupRetentionService} and removes
 * files, never rows (§6.10.3 rule 4, D19).
 *
 * The archive is built with `mysqldump` plus `ZipArchive` rather than through
 * `spatie/laravel-backup`: that package is named in §6.10.1 and is not installed, and a service that
 * fails to load because a composer dependency is missing would take the whole ops panel with it. The
 * shape of the archive — `db-dumps/mysql-<database>.sql` at the root — is deliberately the shape the
 * restore runbook types by hand (§6.10.5 step 7), so the two stay interchangeable.
 */
final class BackupService
{
    use WritesAuditTrail;

    /**
     * The mutual-exclusion key. Its TTL is also how long a `running` row is believed (§6.2 item 1).
     */
    public const LOCK_KEY = 'ops.backup.running';

    public const LOCK_TTL_SECONDS = 7200;

    /**
     * An hour for the dump and an hour for the archive. Longer than any run on this dataset, and
     * short enough that a hung `mysqldump` is reported the same day rather than holding the lock
     * until somebody notices there have been no backups for a week.
     */
    public const DUMP_TIMEOUT_SECONDS = 3600;

    /**
     * §6.2 item 3, exactly. `--single-transaction` is the load-bearing one; see the class note.
     */
    public const MYSQLDUMP_FLAGS = [
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--default-character-set=utf8mb4',
    ];

    /**
     * How many entries are added before the zip is closed and reopened.
     *
     * `ZipArchive` holds a file descriptor per pending entry until `close()`, so a storage tree of
     * ten thousand files exhausts the process limit and fails at `close()` — after every expensive
     * part of the run has already succeeded.
     */
    private const ZIP_FLUSH_EVERY = 512;

    /**
     * Follow-up work that belongs to phase-24-25 §10.5 and ships in a later slice. Dispatched only
     * when the class is present, so a backup cannot fail because the job that copies it offsite has
     * not been written yet.
     */
    private const JOB_VERIFY = VerifyBackupJob::class;

    private const JOB_OFFSITE = CopyBackupOffsite::class;

    private const EVENT_STARTED = BackupStarted::class;

    private const EVENT_COMPLETED = BackupCompleted::class;

    private const EVENT_FAILED = BackupFailed::class;

    public function __construct(
        private readonly BackupPathResolver $paths,
        private readonly BackupRetentionService $retention,
        private readonly BackupVerificationService $verification,
    ) {}

    /**
     * Take a backup, in the order §6.2 lays out.
     *
     * Throws on failure **after** the row has been closed as `failed` and the event dispatched: the
     * row is the evidence and the exception is the alarm, and a scheduled job that swallowed the
     * exception would report a successful night.
     */
    public function run(
        BackupType $type,
        BackupTrigger $trigger,
        ?string $reason = null,
        ?User $actor = null,
    ): BackupRun {
        $reason = $reason === null ? null : trim($reason);

        // The Form Request refuses a manual run without a reason before it reaches here. This is the
        // second line, for the console path and for a caller that built the arguments itself.
        if ($trigger->requiresReason() && ($reason === null || $reason === '')) {
            throw new RuntimeException('A manual backup needs a written reason; there is no default for "why".');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException(
                'A backup is already running. Two dumps of the same database are never started at once — '
                .'wait for the running one to finish, or clear '.self::LOCK_KEY.' if it was left behind by a killed process.'
            );
        }

        // Everything from here to the `finally` is inside the try, including opening the row. A
        // throw between acquiring the lock and entering a try would hold `ops.backup.running` for
        // its full two hours, and every scheduled backup in that window would refuse to start
        // because of a failure that lasted a millisecond.
        $run = null;
        $workspace = null;
        $startedAt = null;

        try {
            // Both decided before the row is written, and both written onto it at creation, because
            // neither is a lifecycle column: `BackupRun::MUTABLE_COLUMNS` refuses a later change to
            // `is_encrypted` and `includes_env` on purpose (§2.1). What an archive claims about its
            // own secrecy is fixed when it is created, or it is a claim somebody can edit.
            $encrypt = $this->shouldEncrypt();
            $includeEnv = $this->shouldIncludeEnv($encrypt);

            $run = $this->open($type, $trigger, $reason, $actor, $encrypt, $includeEnv);
            $this->dispatchEvent(self::EVENT_STARTED, $run);

            $workspace = $this->paths->workspace();
            $startedAt = Carbon::now();

            $result = $this->build($run, $workspace, $type, $encrypt, $includeEnv);

            $this->close($run, $result, $startedAt);
            $this->afterSuccess($run, $reason);

            return $run->refresh();
        } catch (Throwable $exception) {
            if ($run !== null) {
                $this->fail($run, $exception, $startedAt ?? Carbon::now());
            }

            throw $exception;
        } finally {
            if ($workspace !== null) {
                $this->paths->discardWorkspace($workspace);
            }

            $lock->release();
        }
    }

    /**
     * Whether a backup is in flight.
     *
     * Answered from the table rather than from the lock, because this is the question a screen asks
     * and a screen wants the run, not a boolean nobody can explain. The lock is what actually
     * prevents a second dump — a read of this method followed by a `run()` is a race, and the lock
     * is the thing that is not.
     *
     * The window is bounded by the lock's TTL on purpose: a process killed mid-dump leaves a
     * `running` row for ever, and after two hours that row is evidence of a crash rather than a
     * claim that something is still working.
     */
    public function isRunning(): bool
    {
        return BackupRun::query()
            ->where('status', BackupStatus::Running->value)
            ->where('started_at', '>=', Carbon::now()->subSeconds(self::LOCK_TTL_SECONDS))
            ->exists();
    }

    /**
     * The newest archive of a type that is `completed` and still has its file.
     *
     * "Usable" is `BackupStatus::isUsable()` and nothing else. A pruned row and a failed row both
     * describe a backup that is not there.
     */
    public function latestUsable(BackupType $type): ?BackupRun
    {
        return BackupRun::query()
            ->where('type', $type->value)
            ->where('status', BackupStatus::Completed->value)
            ->whereNull('file_pruned_at')
            ->whereNotNull('path')
            ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
            ->orderByDesc('id')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * Open the row: `running`, `started_at`, and the retention class it was born with (§2.1).
     */
    private function open(
        BackupType $type,
        BackupTrigger $trigger,
        ?string $reason,
        ?User $actor,
        bool $encrypt,
        bool $includeEnv,
    ): BackupRun {
        return DB::transaction(function () use ($type, $trigger, $reason, $actor, $encrypt, $includeEnv): BackupRun {
            // `forceFill`, not mass assignment: every value here is written by this service from an
            // enum or a setting, and none of it comes from a request. A row that silently lost
            // `verification_status` because the model's `$fillable` had not caught up would be a row
            // the go-live check reads as "never verified" for ever.
            $run = (new BackupRun)->forceFill([
                'uuid' => (string) Str::ulid(),
                'type' => $type,
                'status' => BackupStatus::Running,
                'trigger' => $trigger,
                'disk' => $this->paths->diskName(),
                'database_name' => $type->includesDatabase() ? $this->paths->databaseName() : null,
                'is_encrypted' => $encrypt,
                'includes_env' => $includeEnv,
                'started_at' => Carbon::now(),
                'reason' => $reason,
                'app_version' => $this->appVersion(),
                'php_version' => mb_substr(PHP_VERSION, 0, 16),
                'verification_status' => BackupVerificationStatus::Unverified,
            ]);

            if ($actor !== null) {
                $run->created_by = $actor->getKey();
                $run->updated_by = $actor->getKey();
            }

            // Stamped before the insert so the row is never briefly classified as something it is
            // not: `classify()` asks whether this day already has a representative, and the answer
            // changes the moment this row exists.
            $run->retention_class = $this->retention->classify($run);
            $run->retention_until = $this->retention->retentionUntil($run->retention_class, $run->started_at);

            $run->save();

            return $run;
        });
    }

    /**
     * Close the row as `completed` with the proof figures.
     *
     * @param  array<string, mixed>  $result
     */
    private function close(BackupRun $run, array $result, Carbon $startedAt): void
    {
        DB::transaction(function () use ($run, $result, $startedAt): void {
            $finishedAt = Carbon::now();

            $run->forceFill([
                'status' => BackupStatus::Completed,
                'path' => $result['path'],
                'filename' => $result['filename'],
                'size_bytes' => $result['size_bytes'],
                'checksum_sha256' => $result['checksum'],
                // `is_encrypted` and `includes_env` are deliberately absent: they were stamped at
                // creation and the model refuses to change them (§2.1).
                'table_count' => $result['table_count'],
                'row_count_total' => $result['row_count_total'],
                'file_count' => $result['file_count'],
                'finished_at' => $finishedAt,
                'duration_seconds' => (int) max(0, $finishedAt->diffInSeconds($startedAt, absolute: true)),
                'notes' => $result['notes'],
            ])->save();
        });
    }

    /**
     * Close the row as `failed`, with the exception class and a scrubbed message (§2.1).
     *
     * The write is its own transaction and its own try: a failure recorder that throws leaves the
     * row `running` for ever, which is the state this method exists to prevent.
     */
    private function fail(BackupRun $run, Throwable $exception, Carbon $startedAt): void
    {
        try {
            DB::transaction(function () use ($run, $exception, $startedAt): void {
                $finishedAt = Carbon::now();

                $run->forceFill([
                    'status' => BackupStatus::Failed,
                    'error_class' => mb_substr($exception::class, 0, 191),
                    'error_message' => $this->scrub($exception->getMessage()),
                    'finished_at' => $finishedAt,
                    'duration_seconds' => (int) max(0, $finishedAt->diffInSeconds($startedAt, absolute: true)),
                ])->save();
            });
        } catch (Throwable $inner) {
            Log::error('The backup_runs row could not be closed as failed.', [
                'backup_run' => $run->uuid,
                'exception' => $inner::class,
            ]);
        }

        Log::error('Backup failed.', [
            'backup_run' => $run->uuid,
            'type' => $run->type->value,
            'trigger' => $run->trigger->value,
            'exception' => $exception::class,
            'message' => $this->scrub($exception->getMessage()),
        ]);

        // The notification this event produces cannot be switched off: `backup.notify_on_failure` is
        // readonly true, because a failure nobody hears about is discovered on the day it matters.
        $this->dispatchEvent(self::EVENT_FAILED, $run);
    }

    /**
     * Activity row, events, and the two follow-up jobs (§6.2 items 7 and 9).
     */
    private function afterSuccess(BackupRun $run, ?string $reason): void
    {
        $this->audit(
            $run,
            sprintf('Backup completed (%s, %s)', $run->type->value, $run->trigger->value),
            [
                // Nothing here is a secret: a size, a checksum and a path. The archive password is
                // not in this array and must never be added to it.
                'uuid' => $run->uuid,
                'type' => $run->type->value,
                'trigger' => $run->trigger->value,
                'disk' => $run->disk,
                'path' => $run->path,
                'size_bytes' => $run->size_bytes,
                'checksum_sha256' => $run->checksum_sha256,
                'is_encrypted' => $run->is_encrypted,
                'includes_env' => $run->includes_env,
                'row_count_total' => $run->row_count_total,
            ],
            'backups',
            $reason,
        );

        $this->dispatchEvent(self::EVENT_COMPLETED, $run);

        if ((bool) setting('backup.verify_checksum_daily', true)) {
            $this->dispatchJob(self::JOB_VERIFY, $run);
        }

        if ($this->paths->offsiteDiskName() !== null) {
            $this->dispatchJob(self::JOB_OFFSITE, $run);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Building the archive
    |--------------------------------------------------------------------------
    */

    /**
     * Dump, collect, zip, store, checksum.
     *
     * @return array<string, mixed>
     */
    private function build(
        BackupRun $run,
        string $workspace,
        BackupType $type,
        bool $encrypt,
        bool $includeEnv,
    ): array {
        $notes = [];
        $dump = null;
        $tableCount = null;
        $rowCountTotal = null;
        $files = [];

        if ($type->includesDatabase()) {
            $dump = $this->dumpDatabase($workspace);
            $database = $this->paths->databaseName();
            $tableCount = $this->verification->tableCount($database);

            // `proofFigures()` reports a table that is not there as -1, which is the right answer
            // for a restore diff and the wrong one to add up: a total that went negative would be
            // refused by an unsigned column and take a good backup down with it.
            $rowCountTotal = array_sum(array_filter(
                $this->verification->proofFigures($database),
                static fn (int $count): bool => $count >= 0,
            ));
        }

        if ($type->includesFiles()) {
            [$files, $skipped] = $this->collectFiles();

            foreach ($skipped as $path) {
                // Recorded rather than thrown: a configured path that has gone missing is a settings
                // problem, and a backup that refuses to run because of it is a night with no archive
                // at all. The gap is written where the next reader will see it.
                $notes[] = 'Include path missing, skipped: '.$path;
            }
        }

        $filename = $this->paths->filename($type, $run->started_at ?? Carbon::now());
        $staged = $workspace.'/'.$filename;

        $this->writeArchive($staged, $run, $dump, $files, $includeEnv, $encrypt, [
            'table_count' => $tableCount,
            'row_count_total' => $rowCountTotal,
        ]);

        // Computed from the staged file, before it is handed to the disk: this is the hash of the
        // bytes this process produced, which is what `backup:verify` re-checks the stored file
        // against. Hashing after the move would prove only that the file has not changed since it
        // was hashed.
        $checksum = hash_file('sha256', $staged);

        if ($checksum === false) {
            throw new RuntimeException('The archive could not be read back to compute its checksum.');
        }

        clearstatcache(true, $staged);
        $stagedSize = (int) filesize($staged);
        $relative = $this->paths->relativePath($type, $filename, $run->started_at ?? Carbon::now());
        $storedSize = $this->paths->store($staged, $relative, $run->disk);

        if ($storedSize !== $stagedSize) {
            throw new RuntimeException(sprintf(
                'The stored archive is %d bytes and the one that was built is %d. The copy is incomplete, '
                .'and an incomplete archive that is recorded as complete is worse than no archive.',
                $storedSize,
                $stagedSize,
            ));
        }

        return [
            'path' => $relative,
            'filename' => $filename,
            'size_bytes' => $storedSize,
            'checksum' => $checksum,
            'table_count' => $tableCount,
            'row_count_total' => $rowCountTotal,
            'file_count' => $type->includesFiles() ? count($files) : null,
            'notes' => $notes === [] ? null : mb_substr(implode(' | ', $notes), 0, 500),
        ];
    }

    /**
     * Run `mysqldump` into the workspace and return the path of the `.sql` it wrote.
     */
    private function dumpDatabase(string $workspace): string
    {
        $connection = $this->dumpConnection();
        $config = (array) config('database.connections.'.$connection);
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(sprintf(
                'A database backup needs a MySQL or MariaDB connection; "%s" is %s.',
                $connection,
                $driver === '' ? 'not configured' : $driver,
            ));
        }

        $binary = $this->binary('backup.mysqldump_path', 'mysqldump');
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('The connection "'.$connection.'" names no database to dump.');
        }

        $defaults = $this->writeDefaultsFile($workspace, $config);
        $target = $workspace.'/'.$this->paths->dumpEntry($database);

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('The dump directory could not be created at '.dirname($target).'.');
        }

        // `--defaults-extra-file` must come first: mysqldump reads the defaults options before
        // anything else on the line and errors out when it meets one later.
        //
        // `--result-file` rather than a shell redirect: it keeps the dump out of this process's
        // memory, and it means the command is an ARRAY, so Symfony escapes every argument itself.
        // That is the stronger form of "always quote the path" (§6.2 item 3, T2) — the project
        // directory contains a space, and the path an operator types may contain several.
        $command = array_merge(
            [$binary, '--defaults-extra-file='.$defaults],
            self::MYSQLDUMP_FLAGS,
            $this->ignoreTableFlags($database),
            ['--result-file='.$target, $database],
        );

        try {
            $result = Process::path($workspace)
                ->timeout(self::DUMP_TIMEOUT_SECONDS)
                ->run($command);

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'mysqldump exited %d: %s',
                    $result->exitCode() ?? -1,
                    mb_substr(trim($result->errorOutput()), 0, 1000),
                ));
            }
        } finally {
            // The one file in this run that must not outlive it, whatever happened above.
            if (is_file($defaults)) {
                @unlink($defaults);
            }
        }

        $this->assertDumpIsComplete($target);

        return $target;
    }

    /**
     * A dump is only finished when `mysqldump` says so.
     *
     * The client writes `-- Dump completed on ...` as its last line. A dump truncated by a full disk
     * or a killed connection can still leave a large, plausible-looking file and, in some builds, a
     * zero exit code — and an archive built around it restores a database that is missing its last
     * tables. Checking the trailer costs one seek and catches exactly that.
     */
    private function assertDumpIsComplete(string $path): void
    {
        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($size === 0) {
            throw new RuntimeException('mysqldump produced an empty file.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The dump could not be reopened to check that it is complete.');
        }

        try {
            fseek($handle, max(0, $size - 512));
            $tail = (string) fread($handle, 512);
        } finally {
            fclose($handle);
        }

        if (! str_contains($tail, 'Dump completed')) {
            throw new RuntimeException(
                'The dump has no completion marker, so it was truncated. An archive built around a '
                .'truncated dump restores a database missing whatever came last.'
            );
        }
    }

    /**
     * One `--ignore-table=database.table` per `backup.excluded_tables` entry.
     *
     * `jobs` and `failed_jobs` are absent from that setting on purpose — see the class note.
     *
     * @return list<string>
     */
    private function ignoreTableFlags(string $database): array
    {
        $excluded = setting('backup.excluded_tables', []);
        $flags = [];

        foreach (is_array($excluded) ? $excluded : [] as $table) {
            $table = is_string($table) ? trim($table) : '';

            // Only a plain identifier is ever passed through. A settings row is not user input, and
            // it is also not something to hand to a command line unexamined.
            if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                continue;
            }

            $flags[] = '--ignore-table='.$database.'.'.$table;
        }

        return $flags;
    }

    /**
     * Write the `--defaults-extra-file`, 0600, inside the run's private workspace.
     *
     * It lives under `storage/app/backups`, which is private, is excluded from file backups, and is
     * discarded with the workspace — rather than in the system temp directory, which on a shared
     * Windows host is readable by every account on the machine.
     *
     * @param  array<string, mixed>  $config
     */
    private function writeDefaultsFile(string $workspace, array $config): string
    {
        $path = $workspace.'/my.cnf';

        $lines = ['[client]'];

        foreach (['host' => 'host', 'port' => 'port', 'username' => 'user', 'password' => 'password'] as $key => $option) {
            $value = $config[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = $option.'="'.$this->escapeOptionValue((string) $value).'"';
        }

        if (($config['unix_socket'] ?? '') !== '') {
            $lines[] = 'socket="'.$this->escapeOptionValue((string) $config['unix_socket']).'"';
        }

        if (file_put_contents($path, implode("\n", $lines)."\n", LOCK_EX) === false) {
            throw new RuntimeException('The defaults file for mysqldump could not be written.');
        }

        @chmod($path, 0600);

        return $path;
    }

    /**
     * MySQL option files take backslash escapes inside a double-quoted value. A password containing
     * a quote would otherwise end the value early and the client would authenticate with half of it
     * — which fails in a way that reads like a wrong password, and sends an operator to change a
     * password that was right.
     */
    private function escapeOptionValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * The connection mysqldump reads through.
     *
     * D58 separates three MySQL users — runtime DML, migration DDL, backup read-only — and the
     * backup one is `mysql_backup` when it has been configured. Falling back to the default
     * connection keeps a single-user installation working: a backup taken as the application user is
     * a backup; no backup at all is not.
     */
    private function dumpConnection(): string
    {
        $preferred = 'mysql_backup';

        return is_array(config('database.connections.'.$preferred))
            ? $preferred
            : (string) config('database.default');
    }

    /**
     * Validate a configured binary and return it.
     *
     * Existence is checked before the run starts rather than being discovered as a confusing exit
     * code from a shell. The path is never concatenated into a string command; see `dumpDatabase()`.
     */
    private function binary(string $settingKey, string $what): string
    {
        $configured = setting($settingKey);
        $path = is_string($configured) ? trim($configured) : '';

        if ($path === '') {
            throw new RuntimeException(sprintf('No %s path is configured (%s).', $what, $settingKey));
        }

        if (! is_file($path)) {
            throw new RuntimeException(sprintf(
                'The %s at "%s" does not exist. Set %s to the real path — on this host it is usually '
                .'C:/xampp/mysql/bin/%s.exe.',
                $what,
                $path,
                $settingKey,
                $what,
            ));
        }

        if (DIRECTORY_SEPARATOR === '/' && ! is_executable($path)) {
            throw new RuntimeException(sprintf('The %s at "%s" is not executable.', $what, $path));
        }

        return $path;
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    /**
     * The file tree to archive: `backup.include_paths` minus `backup.exclude_paths`.
     *
     * @return array{0: list<array{absolute: string, entry: string}>, 1: list<string>}
     */
    private function collectFiles(): array
    {
        $includes = setting('backup.include_paths', []);
        $excludes = $this->excludedPrefixes();

        $files = [];
        $skipped = [];
        $base = rtrim(str_replace('\\', '/', (string) realpath(base_path())), '/');

        foreach (is_array($includes) ? $includes : [] as $include) {
            $include = is_string($include) ? trim($include) : '';

            if ($include === '' || str_contains($include, '..')) {
                // Refused rather than resolved: a path that climbs out of the application directory
                // is not a path somebody meant to type.
                $skipped[] = $include === '' ? '(empty)' : $include;

                continue;
            }

            $absolute = realpath(base_path($include));

            if ($absolute === false) {
                $skipped[] = $include;

                continue;
            }

            $absolute = str_replace('\\', '/', $absolute);

            if ($base !== '' && ! str_starts_with($absolute.'/', $base.'/')) {
                $skipped[] = $include;

                continue;
            }

            if (is_file($absolute)) {
                $relative = ltrim(mb_substr($absolute, mb_strlen($base)), '/');

                if (! $this->isExcluded($relative, $excludes)) {
                    $files[] = ['absolute' => $absolute, 'entry' => BackupPathResolver::FILES_ENTRY_PREFIX.$relative];
                }

                continue;
            }

            foreach ($this->walk($absolute) as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                $relative = ltrim(mb_substr($path, mb_strlen($base)), '/');

                if ($this->isExcluded($relative, $excludes)) {
                    continue;
                }

                $files[] = ['absolute' => $path, 'entry' => BackupPathResolver::FILES_ENTRY_PREFIX.$relative];
            }
        }

        return [$files, $skipped];
    }

    /**
     * Walk a directory, skipping symlinks.
     *
     * A symlink into a parent directory turns the walk into a loop that never finishes, and a backup
     * that never finishes holds the lock until somebody notices there have been no backups.
     *
     * @return iterable<SplFileInfo>
     */
    private function walk(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Repo-relative prefixes that never enter an archive.
     *
     * `storage/app/backups` is added here whatever the setting says. It is on the default
     * `backup.exclude_paths` already, and it is re-added because an archive that contains last
     * week's archives doubles in size every week and restores nothing extra — and the one reader
     * who removes it from the setting will not be the one who finds out.
     *
     * @return list<string>
     */
    private function excludedPrefixes(): array
    {
        $configured = setting('backup.exclude_paths', []);
        $prefixes = ['storage/app/'.BackupPathResolver::DEFAULT_DISK];

        foreach (is_array($configured) ? $configured : [] as $path) {
            $path = is_string($path) ? trim(str_replace('\\', '/', $path), '/') : '';

            if ($path !== '') {
                $prefixes[] = $path;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function isExcluded(string $relative, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | The archive
    |--------------------------------------------------------------------------
    */

    /**
     * Build the zip.
     *
     * @param  list<array{absolute: string, entry: string}>  $files
     * @param  array<string, mixed>  $figures
     */
    private function writeArchive(
        string $path,
        BackupRun $run,
        ?string $dump,
        array $files,
        bool $includeEnv,
        bool $encrypt,
        array $figures,
    ): void {
        $password = $encrypt ? $this->archivePassword() : null;

        $entries = [];

        if ($dump !== null) {
            $entries[] = ['absolute' => $dump, 'entry' => $this->paths->dumpEntry($this->paths->databaseName())];
        }

        foreach ($files as $file) {
            $entries[] = $file;
        }

        if ($includeEnv) {
            if (! is_file(base_path('.env'))) {
                // The row already says `includes_env = true`, and that column is immutable. An
                // archive that silently went without the file would leave the row lying about what
                // is inside it, so the run fails instead.
                throw new RuntimeException('The environment file disappeared between the run opening and the archive being written.');
            }

            $entries[] = ['absolute' => base_path('.env'), 'entry' => BackupPathResolver::ENV_ENTRY];
        }

        $manifest = $this->manifest($run, $dump !== null, count($files), $includeEnv, $encrypt, $figures);

        $zip = $this->openZip($path, $password, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $added = 0;

        try {
            $zip->addFromString(BackupPathResolver::MANIFEST_ENTRY, $manifest);
            $this->encryptEntry($zip, BackupPathResolver::MANIFEST_ENTRY, $password);

            foreach ($entries as $entry) {
                if (! $zip->addFile($entry['absolute'], $entry['entry'])) {
                    throw new RuntimeException('The archive would not accept '.$entry['entry'].'.');
                }

                $this->encryptEntry($zip, $entry['entry'], $password);

                if (++$added % self::ZIP_FLUSH_EVERY === 0) {
                    // Flush: see ZIP_FLUSH_EVERY. Reopening re-reads the central directory, so the
                    // entries already written keep their encryption; the password has to be set
                    // again because it lives on the handle, not in the file.
                    if (! $zip->close()) {
                        throw new RuntimeException('The archive could not be flushed after '.$added.' entries.');
                    }

                    $zip = $this->openZip($path, $password, 0);
                }
            }
        } catch (Throwable $exception) {
            @$zip->close();

            throw $exception;
        }

        if (! $zip->close()) {
            throw new RuntimeException('The archive could not be closed, so it was not written completely.');
        }

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('The archive is empty after it was written.');
        }
    }

    private function openZip(string $path, ?string $password, int $flags): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, $flags) !== true) {
            throw new RuntimeException('The archive at '.$path.' could not be opened for writing.');
        }

        if ($password !== null && ! $zip->setPassword($password)) {
            throw new RuntimeException('The archive password was refused by the zip library.');
        }

        return $zip;
    }

    /**
     * AES-256 per entry.
     *
     * ZipCrypto — what a plain "password-protected zip" means by default — is broken and has been
     * for twenty years, so it is never an acceptable fallback for an archive that may carry `.env`.
     * A build without AES support fails the run instead.
     */
    private function encryptEntry(ZipArchive $zip, string $entry, ?string $password): void
    {
        if ($password === null) {
            return;
        }

        if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new RuntimeException(
                'This build of ext-zip cannot write AES-256 entries, so an encrypted archive cannot be '
                .'produced. Turn backup.encrypt_archives off — and with it backup.include_env — or install '
                .'a libzip with AES support.'
            );
        }
    }

    /**
     * What is in this archive, for whoever opens it in three years.
     *
     * **No secret is in here.** Not the archive password, not the database password, not a token:
     * the manifest travels with the archive, including to the offsite copy.
     *
     * @param  array<string, mixed>  $figures
     */
    private function manifest(
        BackupRun $run,
        bool $hasDatabase,
        int $fileCount,
        bool $includeEnv,
        bool $encrypt,
        array $figures,
    ): string {
        return (string) json_encode([
            'uuid' => $run->uuid,
            'type' => $run->type->value,
            'trigger' => $run->trigger->value,
            'created_at' => Carbon::now()->toIso8601String(),
            'application' => config('app.name'),
            'app_version' => $this->appVersion(),
            'php_version' => PHP_VERSION,
            'database' => $hasDatabase ? $this->paths->databaseName() : null,
            'dump_entry' => $hasDatabase ? $this->paths->dumpEntry($this->paths->databaseName()) : null,
            'excluded_tables' => setting('backup.excluded_tables', []),
            'include_paths' => setting('backup.include_paths', []),
            'file_count' => $fileCount,
            'table_count' => $figures['table_count'] ?? null,
            'row_count_total' => $figures['row_count_total'] ?? null,
            'is_encrypted' => $encrypt,
            'includes_env' => $includeEnv,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /*
    |--------------------------------------------------------------------------
    | Secrets
    |--------------------------------------------------------------------------
    */

    private function shouldEncrypt(): bool
    {
        if (! (bool) setting('backup.encrypt_archives', false)) {
            return false;
        }

        $password = setting('backup.archive_password');

        if (! is_string($password) || mb_strlen($password) < 16) {
            throw new RuntimeException(
                'backup.encrypt_archives is on and backup.archive_password is missing or too short. '
                .'The run stops here rather than writing an archive that is not encrypted while the '
                .'settings screen says it is.'
            );
        }

        return true;
    }

    /**
     * HD-8, enforced where it actually matters rather than only in the settings form.
     *
     * A settings row written by a seeder, a fixture or a direct update does not pass through
     * `SettingsRegistry::crossFieldErrors()`. If `include_env` is on and the archive is not
     * encrypted, the `.env` is left out and the row records `includes_env = false` — the backup
     * still happens, because the alternative is a night with no archive, and what was actually done
     * is what gets written down.
     */
    private function shouldIncludeEnv(bool $encrypt): bool
    {
        if (! (bool) setting('backup.include_env', false)) {
            return false;
        }

        if (! $encrypt) {
            Log::warning(
                'backup.include_env is on while backup.encrypt_archives is off. The environment file was '
                .'left out of the archive (HD-8): it holds the application key, the database password and '
                .'the mail credentials, so an unencrypted archive carrying one is the whole system.'
            );

            return false;
        }

        if (! is_file(base_path('.env'))) {
            // Recorded as false rather than true-and-absent: `includes_env` is read by the go-live
            // checklist and by whoever decides how carefully an archive has to be handled, and a row
            // claiming a secret is inside an archive that does not contain one is a claim that gets
            // acted on in both directions.
            Log::warning('backup.include_env is on and there is no .env file to include.');

            return false;
        }

        return true;
    }

    /**
     * Read once, handed straight to the zip library, never stored anywhere else.
     */
    private function archivePassword(): string
    {
        $password = setting('backup.archive_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('backup.archive_password is not set.');
        }

        return $password;
    }

    /**
     * Take the archive password out of anything on its way to a column or a log line.
     *
     * `App\Logging\RedactSensitive` (§6.3) scrubs log *context*; this scrubs a message body before
     * it becomes `backup_runs.error_message`, which no log tap ever sees. A zip library that echoes
     * the password it was given into an error string would otherwise write it into a column that the
     * backup screen renders.
     */
    private function scrub(string $message): string
    {
        $message = mb_substr($message, 0, 4000);

        foreach (['backup.archive_password', 'database.connections.'.$this->dumpConnection().'.password'] as $key) {
            $secret = str_starts_with($key, 'backup.') ? setting($key) : config($key);

            if (is_string($secret) && $secret !== '' && mb_strlen($secret) >= 4) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return (string) preg_replace('/(password|passwd|pwd)\s*=\s*\S+/i', '$1=[redacted]', $message);
    }

    /*
    |--------------------------------------------------------------------------
    | Deferred collaborators
    |--------------------------------------------------------------------------
    */

    /**
     * Dispatch a follow-up job when its class has shipped.
     *
     * The jobs of §10.5 land in a later slice. A hard reference would make a successful dump report
     * itself as a failure because the class that copies it offsite does not exist yet, so the guard
     * stays until they are all present — at which point this becomes a plain `dispatch(new Job(...))`.
     */
    private function dispatchJob(string $class, BackupRun $run): void
    {
        if (! class_exists($class)) {
            return;
        }

        try {
            dispatch(new $class($run->getKey()));
        } catch (Throwable $exception) {
            Log::warning('A backup follow-up job could not be dispatched.', [
                'job' => $class,
                'backup_run' => $run->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Same reasoning as {@see dispatchJob()}, for the events of §10.1.
     *
     * `afterCommit` because every listener reads the row, and a listener that runs before the
     * transaction commits reads a row that is not there yet.
     */
    private function dispatchEvent(string $class, BackupRun $run): void
    {
        if (! class_exists($class)) {
            return;
        }

        DB::afterCommit(static function () use ($class, $run): void {
            event(new $class($run));
        });
    }

    private function appVersion(): ?string
    {
        $version = setting('ops.app_version');

        return is_string($version) && $version !== '' ? mb_substr($version, 0, 32) : null;
    }
}
