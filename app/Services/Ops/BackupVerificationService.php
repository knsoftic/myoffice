<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupVerificationStatus;
use App\Models\Ops\BackupRun;
use App\Services\Core\Concerns\WritesAuditTrail;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use ZipArchive;

/**
 * Proves an archive is an archive (phase-24-25 §6.10.4, HD-6).
 *
 * **A backup that has never been restored is not a backup, it is a file with a hopeful name.** That
 * sentence is HD-6 and it is the reason this class has two levels rather than one. The checksum
 * level proves the bytes on disk are the bytes that were written — it catches a truncated write, a
 * half-finished offsite copy, a disk that has rotted — and it proves nothing whatever about whether
 * the dump inside would load. The deep level restores the archive into a scratch database and asks
 * the restored copy the questions that matter: does the schema come forward with no pending
 * migration, do the proof counts match, do the financial constraints hold, does every wallet
 * reconcile. Only a run that got through that is `restore_ok`, and only `restore_ok` satisfies
 * go-live (§6.13 GL-36).
 *
 * **The scratch database is checked against the live one by name, every time, before anything is
 * written.** `backup.restore_scratch_database` is validated in the settings form; it is validated
 * again here, because a rehearsal that restored over production would be the single most expensive
 * mistake this system can make, and the check that prevents it costs one string comparison.
 *
 * **The proof commands are run against the restored copy, not against production.** The default
 * connection is repointed at the scratch database for the length of the two commands and put back in
 * a `finally` — `collaborators:reconcile-wallets` writes a reconciliation row per collaborator, and
 * those rows must land in the scratch database that is about to be dropped rather than in the
 * production table the reconciliation history lives in.
 *
 * **Every database statement here goes through the DDL user, never the runtime one** ({@see
 * ddlConnection()}, D58). §6.9.3 grants `my_office_app` SELECT, INSERT, UPDATE, DELETE and EXECUTE on
 * `my_office`.* and nothing else, so on the hardened install the contract mandates the runtime user
 * cannot create the scratch schema, cannot load a dump into it, cannot drop it and holds no rights on
 * it at all. A verification wired to the default connection would fail at its first statement on
 * every correctly installed production host and succeed on every developer's XAMPP root user — which
 * is the shape of bug that reaches production, and it would keep GL-36 (§6.13) red for ever.
 *
 * **Neither level ever repairs anything** (spine §6.5.4, INV-26). A verification that quietly fixed
 * what it found would make the same problem invisible every week, and a proof that is allowed to
 * edit the thing it is proving is not a proof.
 */
final class BackupVerificationService
{
    use WritesAuditTrail;

    /**
     * The financial and identity spine, counted on both sides of every restore (§6.10.4).
     *
     * The list is fixed rather than derived from the schema: a count over "every table" changes
     * meaning each time a phase ships a table, and a proof whose definition moves cannot be compared
     * with the one from last month.
     */
    public const PROOF_TABLES = [
        'users',
        'roles',
        'permissions',
        'collaborators',
        'collaborator_commission_ledger_entries',
        'collaborator_commission_entitlements',
        'collaborator_wallets',
        'collaborator_payouts',
        'collaborator_payout_allocations',
        'collaborator_referrals',
        'collaborator_commission_settings',
        'student_fees',
        'student_fee_installments',
        'student_fee_discounts',
        'student_fee_payments',
        'project_payments',
        'payment_reversals',
        'invoices',
        'invoice_items',
        'expenses',
        'incomes',
        'students',
        'student_admissions',
        'projects',
        'clients',
        'activity_log',
    ];

    /**
     * The table the money argument is actually about, broken out so it is greppable in a log and on
     * a restore's before/after diff.
     */
    public const LEDGER_TABLE = 'collaborator_commission_ledger_entries';

    /**
     * The runtime connection the scratch database is reached through. Registered in memory for the
     * length of a deep verification and never written to `config/database.php`.
     */
    public const SCRATCH_CONNECTION = 'ops_backup_scratch';

    public const RESTORE_TIMEOUT_SECONDS = 3600;

    public function __construct(
        private readonly BackupPathResolver $paths,
    ) {}

    /**
     * Level one: the file on disk is byte-identical to what was written, and it opens as a zip.
     *
     * Both halves matter. A checksum match on a file whose central directory is corrupt proves the
     * corruption was faithfully copied; a zip that opens with a different hash than the row records
     * is a different archive than the one the run reported.
     */
    public function verifyChecksum(BackupRun $run): BackupRun
    {
        // A workspace even for a checksum: an archive on an offsite disk has to be streamed down
        // before it can be hashed, and the copy is discarded either way.
        $workspace = $this->paths->workspace();

        try {
            [$status, $notes] = $this->checksumVerdict($run, $workspace);
        } finally {
            $this->paths->discardWorkspace($workspace);
        }

        return $this->record($run, $status, $notes);
    }

    /**
     * Level two, HD-6: the archive restores, and the restored copy proves the money.
     *
     * Runs the checksum first — restoring an archive that does not match its own hash would produce
     * a verdict about a file nobody can identify.
     */
    public function verifyByRestore(BackupRun $run): BackupRun
    {
        if (! $run->type->includesDatabase()) {
            return $this->record(
                $run,
                BackupVerificationStatus::Failed,
                'A files-only archive holds no database, so there is nothing to restore into a scratch '
                .'database. Verify the database archive of the same night instead.',
            );
        }

        if ($run->status !== BackupStatus::Completed) {
            return $this->record(
                $run,
                BackupVerificationStatus::Failed,
                'Only a completed run can be restored; this one is '.$run->status->value.'.',
            );
        }

        // Null until the name has been proved not to be the live database. The `finally` below drops
        // whatever this holds, and it must never hold a guess.
        $scratch = null;
        $workspace = $this->paths->workspace();

        try {
            [$checksumStatus, $checksumNotes] = $this->checksumVerdict($run, $workspace);

            if ($checksumStatus !== BackupVerificationStatus::ChecksumOk) {
                return $this->record($run, $checksumStatus, $checksumNotes);
            }

            $scratch = $this->scratchDatabase();
            $dump = $this->extractDump($run, $workspace);

            $this->createScratchDatabase($scratch);
            $this->loadDump($scratch, $dump);
            $this->registerScratchConnection($scratch);

            $notes = $this->proveRestoredCopy($run, $scratch);

            return $this->record($run, BackupVerificationStatus::RestoreOk, $checksumNotes.' '.$notes);
        } catch (Throwable $exception) {
            Log::error('The deep backup verification failed.', [
                'backup_run' => $run->uuid,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return $this->record(
                $run,
                BackupVerificationStatus::Failed,
                'The archive did not restore: '.mb_substr($exception->getMessage(), 0, 1200),
            );
        } finally {
            // The scratch database is dropped whatever happened, including when the proof failed:
            // a half-restored copy of production left lying on the server is a copy of production
            // left lying on the server.
            $this->dropScratchDatabase($scratch);
            $this->paths->discardWorkspace($workspace);
        }
    }

    /**
     * Row counts over {@see PROOF_TABLES}, for a database.
     *
     * A table that does not exist is reported as `-1` rather than `0` or omitted: "this table was
     * not in the archive" and "this table was empty" are different findings, and on a restore the
     * first one is the serious one.
     *
     * @return array<string, int>
     */
    public function proofFigures(string $database, ?string $connection = null): array
    {
        $figures = [];
        $existing = $this->tablesIn($database, $connection);

        foreach (self::PROOF_TABLES as $table) {
            if (! in_array($table, $existing, true)) {
                $figures[$table] = -1;

                continue;
            }

            try {
                $figures[$table] = (int) $this->connection($connection)->table($table)->count();
            } catch (Throwable) {
                $figures[$table] = -1;
            }
        }

        return $figures;
    }

    /**
     * How many tables a database holds. Written onto `backup_runs.table_count` at dump time and
     * compared after a restore.
     */
    public function tableCount(string $database, ?string $connection = null): int
    {
        return count($this->tablesIn($database, $connection));
    }

    /*
    |--------------------------------------------------------------------------
    | Checksum
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: BackupVerificationStatus, 1: string}
     */
    private function checksumVerdict(BackupRun $run, string $workspace): array
    {
        if ($run->file_pruned_at !== null) {
            return [
                BackupVerificationStatus::Failed,
                'The file was pruned by retention on '.$run->file_pruned_at->toDateString().'; there is nothing left to check.',
            ];
        }

        $path = $this->paths->materialize($run, $workspace);

        if ($path === null) {
            return [
                BackupVerificationStatus::Failed,
                'The archive is not on disk '.$run->disk.' at '.(string) $run->path.'.',
            ];
        }

        if ($run->checksum_sha256 === null || $run->checksum_sha256 === '') {
            return [
                BackupVerificationStatus::Failed,
                'The run recorded no checksum, so the file on disk cannot be proved to be the one it wrote.',
            ];
        }

        $actual = hash_file('sha256', $path);

        if ($actual === false) {
            return [BackupVerificationStatus::Failed, 'The archive could not be read.'];
        }

        if (! hash_equals((string) $run->checksum_sha256, $actual)) {
            return [
                BackupVerificationStatus::Failed,
                sprintf(
                    'The checksum does not match: the row says %s and the file hashes to %s. The archive on '
                    .'disk is not the archive that was written.',
                    mb_substr((string) $run->checksum_sha256, 0, 16).'…',
                    mb_substr($actual, 0, 16).'…',
                ),
            ];
        }

        if (! $this->opensAsZip($path)) {
            return [
                BackupVerificationStatus::Failed,
                'The checksum matches and the file does not open as a zip, so it was written corrupt rather '
                .'than corrupted since.',
            ];
        }

        $size = (int) filesize($path);

        return [
            BackupVerificationStatus::ChecksumOk,
            sprintf('Checksum matched and the archive opens (%d bytes).', $size),
        ];
    }

    /**
     * `CHECKCONS` makes libzip verify the central directory against the entries rather than trusting
     * the header — which is the difference between "the file starts like a zip" and "the file is one".
     */
    private function opensAsZip(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        $entries = $zip->numFiles;
        $zip->close();

        return $entries > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | The deep proof
    |--------------------------------------------------------------------------
    */

    /**
     * Pull the `.sql` out of the archive into the workspace.
     *
     * An encrypted archive is opened with `backup.archive_password`, which is read here and goes
     * nowhere else — not into a log line, not into the verification notes, not into the exception
     * message if the extraction fails.
     */
    private function extractDump(BackupRun $run, string $workspace): string
    {
        $path = $this->paths->materialize($run, $workspace);

        if ($path === null) {
            throw new RuntimeException('The archive is not on disk.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The archive would not open.');
        }

        try {
            if ($run->is_encrypted && ! $zip->setPassword($this->archivePassword())) {
                throw new RuntimeException('The archive is encrypted and the stored password was refused.');
            }

            $entry = $this->dumpEntryIn($zip, $run);

            if ($entry === null) {
                throw new RuntimeException('The archive holds no database dump.');
            }

            if (! $zip->extractTo($workspace, [$entry])) {
                throw new RuntimeException(
                    'The dump could not be extracted'
                    .($run->is_encrypted ? ' — the archive password may not be the one it was written with.' : '.')
                );
            }
        } finally {
            $zip->close();
        }

        $extracted = $workspace.'/'.$entry;

        if (! is_file($extracted) || filesize($extracted) === 0) {
            throw new RuntimeException('The extracted dump is empty.');
        }

        return $extracted;
    }

    /**
     * The dump's entry name, taken from the archive rather than assumed.
     *
     * An archive written when the database had another name still has to restore, so the expected
     * name is tried first and any `db-dumps/*.sql` accepted after it.
     */
    private function dumpEntryIn(ZipArchive $zip, BackupRun $run): ?string
    {
        $expected = $this->paths->dumpEntry((string) ($run->database_name ?? $this->paths->databaseName()));

        if ($zip->locateName($expected) !== false) {
            return $expected;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The scratch database name, refused when it is the live one.
     *
     * Checked case-insensitively: MySQL on Windows folds database names, so `MY_OFFICE` and
     * `my_office` are the same schema and a comparison that missed that would be a comparison that
     * passed right before it destroyed production.
     */
    private function scratchDatabase(): string
    {
        $configured = setting('backup.restore_scratch_database', 'my_office_restore_test');
        $scratch = is_string($configured) ? trim($configured) : '';

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $scratch) !== 1) {
            throw new RuntimeException(
                'backup.restore_scratch_database is not a plain identifier, and this name is about to be '
                .'used in a CREATE DATABASE and a DROP DATABASE.'
            );
        }

        $live = $this->paths->databaseName();

        if (mb_strtolower($scratch) === mb_strtolower($live)) {
            throw new RuntimeException(sprintf(
                'backup.restore_scratch_database is "%s", which is the live database. A rehearsal does not '
                .'get to eat production; set it to something like %s_restore_test.',
                $scratch,
                $live,
            ));
        }

        return $scratch;
    }

    private function createScratchDatabase(string $scratch): void
    {
        // Dropped first: a scratch database left behind by a run that was killed would otherwise be
        // restored *into*, and the proof counts would then be the sum of two archives.
        $this->dropScratchDatabase($scratch);

        // The name is interpolated into DDL, and the only reason that is safe is
        // {@see scratchDatabase()}: it has already refused anything that is not
        // `[A-Za-z0-9_]{1,64}`. A database name cannot be a bound parameter, so the validation has
        // to happen before the string is built, not inside it.
        $this->mysql([
            '--execute=CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }

    private function dropScratchDatabase(?string $scratch): void
    {
        if ($scratch === null) {
            return;
        }

        try {
            DB::purge(self::SCRATCH_CONNECTION);
        } catch (Throwable) {
            // Nothing was connected. The drop below is what matters.
        }

        try {
            $this->mysql(['--execute=DROP DATABASE IF EXISTS `'.$scratch.'`']);
        } catch (Throwable $exception) {
            Log::warning('The scratch database could not be dropped.', [
                'database' => $scratch,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Feed the dump to the `mysql` client on **stdin**.
     *
     * A stream, not a string: a dump is measured in hundreds of megabytes and reading one into a PHP
     * variable to hand it to a process is how a verification runs the host out of memory at four in
     * the morning. Stdin also avoids the client's `SOURCE` builtin, which takes the rest of the line
     * as a literal filename — and every path on this host contains a space (T2).
     */
    private function loadDump(string $scratch, string $dump): void
    {
        $handle = fopen($dump, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The extracted dump could not be opened.');
        }

        try {
            $this->mysql(['--database='.$scratch], $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * The connection whose credentials are allowed to issue DDL (D58, §6.9.3).
     *
     * The mirror image of {@see BackupService::dumpConnection()}, which resolves the read-only dump
     * user the same way. D58 separates three MySQL users — runtime DML, migration DDL, backup
     * read-only — and **every statement a deep verification issues is either DDL or is addressed to a
     * database the runtime user holds no grant on**: `CREATE DATABASE`, `DROP DATABASE`, the
     * `CREATE TABLE` / `CREATE TRIGGER` / `CREATE ROUTINE` inside the dump, and then the reads and
     * the reconciliation writes on the scratch schema itself. §6.9.3 grants `my_office_app` only
     * SELECT, INSERT, UPDATE, DELETE and EXECUTE on `my_office`.*, so a verification that ran as the
     * runtime user would be denied at the first statement, could never reach `restore_ok`, and would
     * therefore block GL-36 (§6.13) for ever on precisely the hardened install the contract mandates
     * — while passing in local dev on the XAMPP root user, which is why nobody would catch it before
     * production.
     *
     * Falling back to the default connection keeps a single-user installation working, for the same
     * reason the dump does: a rehearsal run as the application user is a rehearsal; no rehearsal at
     * all is not.
     */
    private function ddlConnection(): string
    {
        $preferred = 'mysql_migration';

        return is_array(config('database.connections.'.$preferred))
            ? $preferred
            : (string) config('database.default');
    }

    /**
     * Run the `mysql` client with the credentials in a defaults file, never on the command line.
     *
     * The credentials are the DDL user's ({@see ddlConnection()}): every caller of this method is
     * issuing `CREATE DATABASE`, `DROP DATABASE` or a dump load, and none of the three is a statement
     * the runtime user is granted.
     *
     * @param  list<string>  $arguments
     * @param  resource|null  $input
     */
    private function mysql(array $arguments, $input = null): void
    {
        $connection = $this->ddlConnection();
        $config = (array) config('database.connections.'.$connection);

        $binary = $this->binary('backup.mysql_path', 'mysql');
        $workspace = $this->paths->workspace();
        $defaults = $this->writeDefaultsFile($workspace, $config);

        try {
            $process = Process::timeout(self::RESTORE_TIMEOUT_SECONDS);

            if ($input !== null) {
                $process = $process->input($input);
            }

            // Array form, so Symfony escapes every argument: the binary path may contain a space and
            // `--defaults-extra-file` has to be the first option the client sees.
            $result = $process->run(array_merge(
                [$binary, '--defaults-extra-file='.$defaults],
                $arguments,
            ));

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'the mysql client exited %d: %s',
                    $result->exitCode() ?? -1,
                    mb_substr(trim($result->errorOutput()), 0, 800),
                ));
            }
        } finally {
            if (is_file($defaults)) {
                @unlink($defaults);
            }

            $this->paths->discardWorkspace($workspace);
        }
    }

    /**
     * Register the scratch connection in memory for the length of this verification.
     *
     * Runtime config only. `config/database.php` is not touched, and the connection is purged when
     * the scratch database is dropped.
     *
     * **It clones the DDL connection, not the default one.** The scratch schema is created by the
     * migration user and §6.9.3 grants the runtime user nothing at all on it, so a clone of the
     * default connection would fail to connect — and {@see tablesIn()} swallows that failure, which
     * would report the restored copy as "missing 26 proof tables" instead of "the app user has no
     * rights here" (D58).
     */
    private function registerScratchConnection(string $scratch): void
    {
        $base = (array) config('database.connections.'.$this->ddlConnection());
        $base['database'] = $scratch;

        config(['database.connections.'.self::SCRATCH_CONNECTION => $base]);

        DB::purge(self::SCRATCH_CONNECTION);
    }

    /**
     * Ask the restored copy the four questions of §6.10.4, and return what it answered.
     */
    private function proveRestoredCopy(BackupRun $run, string $scratch): string
    {
        $notes = [];

        // 1 — nothing pending. A dump restored into a codebase that has moved on is a database one
        // migration away from the application it is supposed to serve.
        $pending = $this->pendingMigrations();
        $notes[] = $pending === 0
            ? 'No pending migration.'
            : $pending.' pending migration(s).';

        if ($pending > 0) {
            throw new RuntimeException(
                'The restored copy has '.$pending.' pending migration(s), so it is not the schema this '
                .'release runs against.'
            );
        }

        // 2 — the proof counts.
        $figures = $this->proofFigures($scratch, self::SCRATCH_CONNECTION);
        $missing = array_keys(array_filter($figures, static fn (int $count): bool => $count < 0));

        if ($missing !== []) {
            throw new RuntimeException(
                'The restored copy is missing '.count($missing).' proof table(s): '
                .implode(', ', array_slice($missing, 0, 5)).'.'
            );
        }

        $ledgerRows = $figures[self::LEDGER_TABLE] ?? 0;
        $recorded = (int) ($run->row_count_total ?? 0);
        $restored = array_sum($figures);

        $notes[] = sprintf(
            '%d proof rows restored against %d recorded at dump time; %d ledger entries.',
            $restored,
            $recorded,
            $ledgerRows,
        );

        if ($recorded > 0 && $restored < $recorded) {
            // Only a shortfall is a failure. A restored copy with *more* rows than the dump recorded
            // is impossible from the archive alone, and a count taken a moment after the dump
            // legitimately lags it — the direction that means data was lost is the one that fails.
            throw new RuntimeException(sprintf(
                'The restored copy holds %d proof rows and the dump recorded %d. Rows did not survive the '
                .'archive.',
                $restored,
                $recorded,
            ));
        }

        // 3 and 4 — the money, proved by the commands that own those questions rather than by a
        // second implementation of them here (INV-26).
        $notes[] = $this->runProofCommands($scratch);

        return implode(' ', $notes);
    }

    /**
     * `financial:verify-constraints` and `collaborators:reconcile-wallets`, against the scratch copy.
     *
     * **The default connection is repointed for the length of these two commands.** Neither takes a
     * `--database` option, and `collaborators:reconcile-wallets` *writes* a reconciliation row per
     * collaborator — run without the repoint it would write those rows into production while
     * reporting on a restored copy, which is both wrong and hard to notice. It is put back in a
     * `finally`, and the commands are run read-only: no `--repair`, ever (spine §6.5.4).
     *
     * **The repoint is two moves, and both are load-bearing.** The DDL connection is made the default
     * *and* aimed at the scratch schema. Aiming it alone would do nothing, because both commands
     * reach the database through `DB::` with no connection name and that resolves `database.default`
     * at call time — they would go on reporting on production, and the reconciliation would go on
     * writing its rows there. Making it the default alone would send them to the restored copy as the
     * runtime user, who holds no grant on that schema at all (§6.9.3, D58), so the INSERT would be
     * denied. On a single-user install {@see ddlConnection()} returns the default connection and the
     * first move is a no-op, which is exactly the old behaviour.
     */
    private function runProofCommands(string $scratch): string
    {
        $connection = $this->ddlConnection();
        $previousDefault = (string) config('database.default');
        $original = config('database.connections.'.$connection.'.database');

        config([
            'database.default' => $connection,
            'database.connections.'.$connection.'.database' => $scratch,
        ]);
        DB::purge($connection);

        try {
            $output = new BufferedOutput;
            $constraints = Artisan::call('financial:verify-constraints', [], $output);

            if ($constraints !== 0) {
                throw new RuntimeException(
                    'financial:verify-constraints failed on the restored copy: '
                    .mb_substr(trim($output->fetch()), 0, 600)
                );
            }

            $output = new BufferedOutput;
            $wallets = Artisan::call('collaborators:reconcile-wallets', [
                '--force' => true,
                '--run-type' => 'test',
            ], $output);

            if ($wallets !== 0) {
                throw new RuntimeException(
                    'The wallet reconciliation reported a structural failure on the restored copy: '
                    .mb_substr(trim($output->fetch()), 0, 600)
                );
            }

            return 'Constraints held and every wallet reconciled on the restored copy.';
        } finally {
            // Both moves are undone, and the connection is purged so nothing keeps a handle on a
            // schema that is about to be dropped. The order does not matter; the `finally` does —
            // a throw out of either command with the default still aimed at the scratch copy would
            // leave the rest of the request writing into a database that no longer exists.
            config([
                'database.default' => $previousDefault,
                'database.connections.'.$connection.'.database' => $original,
            ]);
            DB::purge($connection);
        }
    }

    /**
     * How many migrations the restored copy has not run.
     *
     * Read from the table rather than from `migrate:status`, whose output format is a presentation
     * detail that has changed between Laravel versions and would take this proof with it.
     */
    private function pendingMigrations(): int
    {
        $files = glob(database_path('migrations/*.php'));
        $files = $files === false ? [] : $files;

        $ran = $this->connection(self::SCRATCH_CONNECTION)
            ->table((string) config('database.migrations.table', 'migrations'))
            ->pluck('migration')
            ->all();

        $pending = 0;

        foreach ($files as $file) {
            if (! in_array(basename($file, '.php'), $ran, true)) {
                $pending++;
            }
        }

        return $pending;
    }

    /*
    |--------------------------------------------------------------------------
    | Writing the verdict
    |--------------------------------------------------------------------------
    */

    /**
     * Stamp the verdict on the row, in a transaction like every other write to this table.
     *
     * `verified_at` is stamped for a failure too: "checked on Sunday and it did not verify" is a
     * fact with a date, and a null there would read as "never checked".
     */
    private function record(BackupRun $run, BackupVerificationStatus $status, string $notes): BackupRun
    {
        DB::transaction(function () use ($run, $status, $notes): void {
            $run->forceFill([
                'verification_status' => $status,
                'verified_at' => Carbon::now(),
                'verification_notes' => mb_substr(trim($notes), 0, 4000),
            ])->save();
        });

        // A failed verification and a `restore_ok` both get an activity row: one is an incident and
        // the other is the go-live evidence of HD-6. A daily `checksum_ok` does not — three hundred
        // and sixty-five identical rows a year is how an activity log stops being read, and the
        // verdict is already on the run itself.
        if ($status !== BackupVerificationStatus::ChecksumOk) {
            $this->audit(
                $run,
                'Backup verification: '.$status->value,
                [
                    'uuid' => $run->uuid,
                    'verification_status' => $status->value,
                    'notes' => mb_substr(trim($notes), 0, 1000),
                ],
                'backups',
            );
        }

        if ($status === BackupVerificationStatus::Failed) {
            Log::error('A backup failed verification.', [
                'backup_run' => $run->uuid,
                'notes' => mb_substr(trim($notes), 0, 1000),
            ]);
        }

        return $run->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Table names in a database, read from `information_schema` with a binding.
     *
     * The failure is logged before it is swallowed. `information_schema.TABLES` shows a user only the
     * schemas it holds a privilege on, so "no rows" and "refused to connect" both arrive here as an
     * empty list — and an empty list turns into "the restored copy is missing 26 proof table(s)",
     * which sends the next reader to look at the archive when the real answer is a missing GRANT on
     * the scratch schema (§6.9.3). The log line is the only place that distinction survives.
     *
     * @return list<string>
     */
    private function tablesIn(string $database, ?string $connection = null): array
    {
        try {
            $rows = $this->connection($connection)->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$database, 'BASE TABLE'],
            );
        } catch (Throwable $exception) {
            Log::warning('The table list for a backup proof could not be read.', [
                'database' => $database,
                'connection' => $connection ?? (string) config('database.default'),
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return [];
        }

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    private function connection(?string $name = null): Connection
    {
        return DB::connection($name);
    }

    /**
     * The same defaults-file trick {@see BackupService} uses, for the same reason: a password on a
     * command line is readable from the process list by everything else on the machine.
     *
     * `unix_socket` is carried across for the same reason the sibling carries it: on a Linux host
     * whose MariaDB listens on a non-default socket, a `[client]` block without it sends the client
     * to `/var/run/mysqld/mysqld.sock` and it fails to connect — and the deep verification would
     * report "the archive did not restore" about an archive it never opened a connection to test.
     *
     * @param  array<string, mixed>  $config
     */
    private function writeDefaultsFile(string $workspace, array $config): string
    {
        $path = $workspace.'/my.cnf';
        $lines = ['[client]'];

        foreach ([
            'host' => 'host',
            'port' => 'port',
            'username' => 'user',
            'password' => 'password',
            'unix_socket' => 'socket',
        ] as $key => $option) {
            $value = $config[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = $option.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        }

        if (file_put_contents($path, implode("\n", $lines)."\n", LOCK_EX) === false) {
            throw new RuntimeException('The defaults file for the mysql client could not be written.');
        }

        @chmod($path, 0600);

        return $path;
    }

    private function binary(string $settingKey, string $what): string
    {
        $configured = setting($settingKey);
        $path = is_string($configured) ? trim($configured) : '';

        if ($path === '') {
            throw new RuntimeException(sprintf('No %s path is configured (%s).', $what, $settingKey));
        }

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('The %s at "%s" does not exist (%s).', $what, $path, $settingKey));
        }

        if (DIRECTORY_SEPARATOR === '/' && ! is_executable($path)) {
            throw new RuntimeException(sprintf('The %s at "%s" is not executable.', $what, $path));
        }

        return $path;
    }

    /**
     * Read once, handed to the zip library, never written down.
     */
    private function archivePassword(): string
    {
        $password = setting('backup.archive_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'This archive is encrypted and backup.archive_password is empty. The password that was set '
                .'when it was written is the only thing that opens it.'
            );
        }

        return $password;
    }
}
