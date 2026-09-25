<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Enums\BackupType;
use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Enums\RestoreStatus;
use App\Enums\RestoreTarget;
use App\Models\Ops\BackupRestore as RestoreRecord;
use App\Models\Ops\BackupRun;
use App\Models\Ops\IntegrityCheckRun;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Ops\BackupPathResolver;
use App\Services\Ops\BackupService;
use App\Services\Ops\BackupVerificationService;
use App\Services\Ops\IntegrityCheckService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Throwable;
use ZipArchive;

/**
 * `backup:restore` — the console path of the restore procedure (phase-24-25 §6.6, §6.10.5).
 *
 * **This is the only command in the application that destroys data on purpose, and every line of it
 * is written on the assumption that it is being typed at two in the morning by somebody who has
 * already had a bad night.** Two things follow from that, and they pull in opposite directions: it
 * has to be genuinely hard to run by accident, and it has to be completely obvious how to run on
 * purpose. So every refusal below prints the exact thing the operator must do next — including the
 * confirmation phrase, in full, ready to copy. A gate that says "wrong phrase" and nothing else
 * teaches the operator to guess, and guessing at this prompt is how the wrong database gets eaten.
 *
 * **The rate limiter guards the screen; the confirmation phrase guards the console.** §6.3.1's
 * `backup-restore` limiter is 1/hour and it is an HTTP throttle — it cannot see a command, because
 * a command has no request to throttle. Nothing in this file consults it, and that is deliberate
 * rather than an omission: during a recovery an operator may legitimately need three attempts in
 * ten minutes, and a limiter that locked the console for fifty minutes in the middle of an incident
 * would be a self-inflicted outage. What stands in front of the console instead is the typed
 * phrase, the mandatory written reason, the pre-restore archive and the verification check — four
 * things nobody passes by holding down Return.
 *
 * **`password.confirm` cannot be honoured here, so `password_confirmed_at` stays null.** That
 * column means "this person re-entered their password before the restore", and in a console there
 * is no session to re-authenticate against. Stamping it anyway would be the worst possible
 * shortcut: {@see RestoreRecord::gatesSatisfied()} reads the stamps precisely so that six months
 * later the question "did the gates pass then" has an answer that was not reconstructed, and a
 * fabricated stamp makes every genuine one worthless. A console restore therefore reads as
 * gates-unsatisfied on that one axis, truthfully, and `notes` says why.
 *
 * **The steps are §6.10.5's, in §6.10.5's order, and the two orderings that look arbitrary are
 * not.** The archive is extracted *before* the row flips to `running`, because a failed extraction
 * has written nothing and must record as `Aborted` rather than `Failed` — the difference is the
 * first thing an operator needs at two in the morning ({@see RestoreStatus}). And the
 * `backup_restores` row is inserted *after* the pre-restore backup and the before-counts, because
 * `pre_restore_backup_run_id`, `row_counts_before` and `ledger_rows_before` are all outside
 * {@see RestoreRecord::MUTABLE_COLUMNS} for ever — they have to be in the INSERT or they can never
 * be written at all.
 *
 * **A restore overwrites the table this restore is being recorded in.** That is not a hypothetical:
 * `backup_restores` and `backup_runs` are inside the dump unless an operator has put them on
 * `backup.excluded_tables`, so step 7 silently deletes the in-flight row and the pre-restore
 * archive's row along with it, and every later `->save()` would update nothing while reporting
 * success. So the row is serialised to a file outside the database before the dump goes in
 * ({@see writeEvidence()}) and re-inserted afterwards ({@see reinstateEvidence()}). The file is the
 * copy of last resort and its path is printed whatever happens — evidence that lives only in the
 * thing being replaced is not evidence.
 *
 * **Nothing here reimplements a service.** The archive is located and materialised by
 * {@see BackupPathResolver}, re-hashed by {@see BackupVerificationService}, the safety copy is taken
 * by {@see BackupService} and the money is proved by {@see IntegrityCheckService} — which runs the
 * suites the spine and Phase 12 own, because a second opinion about whether the ledger balances is
 * worth less than no opinion. The one thing this file does itself is feed the dump to the `mysql`
 * client, because §6.10.5's `BackupRestoreService::execute()` has not landed yet; when it does, the
 * step methods below are what moves into it and this command becomes its thin console wrapper.
 *
 * Exit codes — three, not two, and the third one is the point:
 *
 *   0  completed: restored, migrated forward, counted and proved.
 *   1  aborted: a gate refused. **The target database was not written to.** Try again.
 *   2  failed: it ran and a proof did not hold. The application is still down, and the way back is
 *      the pre-restore archive whose id this command prints.
 *
 * (§6.6's table says 0/1 for this row. The `Aborted`/`Failed` split is what {@see RestoreStatus}
 * exists for, and a monitoring wrapper that cannot tell "nothing happened" from "the database is
 * half-restored" will page the wrong person; the amendment is noted for the log.)
 */
final class BackupRestore extends Command
{
    /**
     * How `{date}` is rendered inside `backup.restore_confirmation_phrase`.
     *
     * Public and a constant because the restore wizard (§8.3) has to substitute **identically** or
     * the phrase an operator reads on screen is not the phrase this command will accept, and the
     * operator's conclusion will be that the phrase is broken rather than that they are in the
     * wrong place. ISO rather than the display format from `setting('general.date_format')`: a
     * phrase that has to be typed exactly cannot be rendered in a format where `03/04` means two
     * different days depending on who configured the install.
     */
    public const PHRASE_DATE_FORMAT = 'Y-m-d';

    /**
     * §6.10.5's gate 4, mirrored from the Form Request's `min:20`. "testing" does not pass review,
     * and it is not this command's job to be the lenient half of the same rule.
     */
    public const REASON_MIN_LENGTH = 20;

    /** `backup_restores.reason` is `string(500)`; a truncated reason is a changed reason. */
    public const REASON_MAX_LENGTH = 500;

    /**
     * A dump load has no business taking longer than an hour, and a process with no timeout is a
     * process that holds the site down until somebody notices in the morning. Same ceiling as
     * {@see BackupVerificationService::RESTORE_TIMEOUT_SECONDS} — the two are doing the same work.
     */
    public const LOAD_TIMEOUT_SECONDS = 3600;

    /** Where the pre-restore copy of this row is written. See the class note. */
    public const EVIDENCE_DIRECTORY = 'restore-evidence';

    private const EXIT_OK = 0;

    private const EXIT_ABORTED = 1;

    private const EXIT_FAILED = 2;

    protected $signature = 'backup:restore
                            {--backup= : The archive to restore — a backup_runs id or uuid}
                            {--target= : local, staging or production; the target decides how many gates you pass}
                            {--database= : The database to overwrite; defaults to the one this application uses}
                            {--reason= : Why, in at least 20 characters. Mandatory — see the class note}
                            {--confirm= : The confirmation phrase, typed exactly, case included}';

    protected $description = 'Restore a verified archive over a database, through the four gates of §6.10.5.';

    /*
    |--------------------------------------------------------------------------
    | Invocation state
    |--------------------------------------------------------------------------
    |
    | Held on the command rather than threaded through fifteen signatures. A Command is resolved once
    | per invocation, so there is no state here that outlives one restore.
    */

    private BackupRun $archive;

    private RestoreTarget $target;

    private string $database;

    private string $reason;

    private User $actor;

    /**
     * When this invocation began walking the procedure.
     *
     * **`chk_brs_window` is `finished_at IS NULL OR started_at IS NOT NULL`, so a row that ends has
     * to carry a start.** An abort at step 2 never reaches step 7b, which is the only other place
     * `started_at` is stamped, so writing `finished_at` without this would bounce the very INSERT
     * that records the refusal off the constraint — and a refused production restore that left no
     * trace at all is exactly the attempt somebody would want no trace of.
     *
     * So the column reads "when the attempt started" on an aborted row and "when the dump started"
     * on one that ran. That is not an ambiguity to resolve with a second timestamp: whether the
     * target database was written to is the *status's* job to answer
     * ({@see RestoreStatus::touchedTheDatabase()}), and a reader who takes it from a clock instead
     * is the reader this table is written for.
     */
    private CarbonInterface $attemptStartedAt;

    /** Stamped when the typed phrase matched, null for a target that needs no phrase. */
    private ?CarbonInterface $confirmedAt = null;

    /** Set by step 2 and written onto the row as it stands, whether the row is a success or an abort. */
    private bool $checksumVerified = false;

    /** True when `--database` names the database this application itself is connected to. */
    private bool $replacesOwnDatabase = false;

    private ?BackupRun $preBackup = null;

    /** @var array<string, int>|null */
    private ?array $countsBefore = null;

    private ?int $ledgerBefore = null;

    private ?RestoreRecord $restore = null;

    /** @var array<string, mixed> */
    private array $evidence = [];

    private ?string $evidenceFile = null;

    /** The scratch directory the dump is extracted into; discarded in `handle()`'s `finally`. */
    private ?string $workspace = null;

    /**
     * Has the `mysql` client been handed the dump?
     *
     * Tracked here rather than read back off the row, because the row is one of the things the dump
     * may have deleted. **"Was the database written to" is the question the whole `Aborted`/`Failed`
     * split turns on, and it must not be answerable only by a record that the write itself can
     * destroy.**
     */
    private bool $databaseTouched = false;

    private bool $broughtDown = false;

    public function handle(
        BackupPathResolver $paths,
        BackupService $backups,
        BackupVerificationService $verification,
        IntegrityCheckService $integrity,
    ): int {
        // Before the first gate, because the first gate can already be the thing that ends this
        // invocation — and an ending needs a start. See {@see $attemptStartedAt}.
        $this->attemptStartedAt = Carbon::now();

        /*
        | The gates that cannot produce a row, first.
        |
        | `backup_run_id`, `target`, `database_name`, `reason` and `requested_by` are all NOT NULL
        | and all immutable, so until every one of them is known and valid there is literally no row
        | that can be written. That is not a gap in the audit trail: each refusal in this block means
        | the command was typed wrong, nobody got as far as touching an archive, and the console
        | output is the whole of what happened.
        */
        if (! $this->resolveArchive($paths)) {
            return self::EXIT_ABORTED;
        }

        if (! $this->resolveTarget()) {
            return self::EXIT_ABORTED;
        }

        if (! $this->resolveReason()) {
            return self::EXIT_ABORTED;
        }

        if (! $this->resolveDatabase($paths)) {
            return self::EXIT_ABORTED;
        }

        if (! $this->assertArchiveWasProved()) {
            return self::EXIT_ABORTED;
        }

        if (! $this->assertNoBackupInFlight($backups)) {
            return self::EXIT_ABORTED;
        }

        $confirmedAt = $this->assertConfirmationPhrase();

        if ($confirmedAt === false) {
            return self::EXIT_ABORTED;
        }

        $this->confirmedAt = $confirmedAt;

        if (! $this->resolveActor()) {
            return self::EXIT_ABORTED;
        }

        // Step 1 — announce. The screen and the console print the same three facts (§6.10.5 step 1).
        $this->announce();

        try {
            // Step 2 — verify the archive *now*, not just "at some point in the past".
            if (! $this->verifyArchiveNow($verification)) {
                return $this->recordAbort('The archive did not match its own checksum, so it was not restored.');
            }

            // Step 3 — maintenance mode. Step 4 — the worker.
            if (! $this->enterMaintenance()) {
                return $this->recordAbort('The application could not be put into maintenance mode; nothing was restored.');
            }

            if (! $this->confirmWorkerIsStopped()) {
                return $this->recordAbort('The operator stopped at the queue-worker gate.');
            }

            // Step 5 — the pre-restore copy. There is no undo for an undo.
            if (! $this->takePreRestoreBackup($backups)) {
                return $this->recordAbort('The pre-restore backup did not complete, so the restore was not started.');
            }

            // Step 6 — before-counts.
            $this->captureBeforeCounts($verification);

            // The first moment a row can exist. Everything immutable about this restore is now known.
            $this->recordRequested();

            // Step 7a — extract. Still nothing written to the target, so a failure here aborts.
            $dump = null;

            try {
                $dump = $this->extractDump($paths);
            } catch (Throwable $exception) {
                return $this->recordAbort('The dump could not be read out of the archive: '.$this->short($exception));
            }

            $this->writeEvidence();

            // Step 7b — the point of no return.
            return $this->overwriteAndProve($dump, $verification, $integrity);
        } catch (Throwable $exception) {
            /*
            | A throw that escaped every step's own handling. Which state it records depends on one
            | question and only one: had the dump started going in? Answered from
            | {@see $databaseTouched} rather than from the row, because the row is one of the things
            | the dump may have deleted — and recording "aborted, nothing was written" over a database
            | that was in fact half-replaced is the single most misleading row this table could hold.
            */
            Log::error('backup:restore crashed.', [
                'archive' => $this->archive->uuid ?? null,
                'restore' => $this->restore?->uuid,
                'exception' => $exception::class,
                'message' => $this->short($exception),
            ]);

            if ($this->databaseTouched) {
                return $this->recordFailure('The restore crashed after the dump had started: '.$this->short($exception), $exception);
            }

            return $this->recordAbort('The restore crashed before anything was written: '.$this->short($exception), $exception);
        } finally {
            /*
            | The extracted dump is a complete, unencrypted copy of the database sitting on disk. It
            | is discarded whatever happened — including on the success path, where an operator's
            | attention is on the site coming back up rather than on a scratch directory holding
            | every fee payment in plaintext.
            */
            if ($this->workspace !== null) {
                $paths->discardWorkspace($this->workspace);
            }
        }
    }

    /**
     * Steps 7b to 13 — everything after the target database has been written to.
     *
     * Split out because every exit from here is a `Failed`, never an `Aborted`: once the `mysql`
     * client has been handed the dump, the target holds some mixture of two databases and the
     * runbook's answer is the pre-restore archive, not "try again".
     *
     * §6.10.5 calls this `execute()`, and it is not called that here for one flat reason: Symfony's
     * `Command::execute()` is the console lifecycle hook and overriding it privately is a fatal
     * error at boot. The name belongs to `BackupRestoreService::execute()` when that class lands.
     */
    private function overwriteAndProve(
        string $dump,
        BackupVerificationService $verification,
        IntegrityCheckService $integrity,
    ): int {
        $this->transition(RestoreStatus::Running, ['started_at' => Carbon::now()]);

        // Re-captured with the `running` stamps on it, so the copy that survives the swap is the row
        // as it was a heartbeat before the dump went in, not as it was two steps earlier.
        $this->refreshEvidence();

        // Set *before* the client runs, never after: a process killed halfway through the load has
        // still written, and a flag set on the way out would call that an abort.
        $this->databaseTouched = true;

        $this->components->task('  step 7  loading the dump into '.$this->database, function () use ($dump): bool {
            $this->loadDump($dump);

            return true;
        });

        // The row we have been writing to may have just been deleted by the dump. See the class note.
        $this->reinstateEvidence();

        if (! $this->replacesOwnDatabase) {
            /*
            | Steps 8 to 11 all address the database this application is connected to. When the
            | operator restored into a side database — a rehearsal, a forensic copy — running them
            | would migrate, cache-clear and money-check *production* on the strength of a restore
            | that never touched it, and then stamp the result onto this row as proof. The archive's
            | own deep verification (`backup:verify --deep`) is the tool that proves a side restore.
            */
            $this->warn('  steps 8-11 skipped: '.$this->database.' is not the database this application uses,');
            $this->line('           so migrating it, clearing caches and proving the money would all be aimed');
            $this->line('           at the wrong schema. Use "backup:verify --backup='.$this->archive->id.' --deep" to');
            $this->line('           prove an archive without touching anything.');

            return $this->recordCompletion([
                'skipped' => 'steps 8-11 (the restored database is not the application database)',
            ]);
        }

        // Step 8 — bring the schema forward.
        $migrationConnection = $this->migrationConnection();

        $migrateExit = Artisan::call('migrate', [
            '--database' => $migrationConnection,
            '--force' => true,
        ], $this->output->getOutput());

        if ($migrateExit !== 0) {
            return $this->recordFailure(sprintf(
                'The archive is in, but "migrate --database=%s --force" exited %d, so the schema is behind the code.',
                $migrationConnection,
                $migrateExit,
            ));
        }

        $pending = $this->pendingMigrations();

        if ($pending > 0) {
            return $this->recordFailure(sprintf(
                'The archive is in, but %d migration(s) are still pending. The code is ahead of the schema.',
                $pending,
            ));
        }

        // Step 9 — clear derived state. A permission cache from before the restore describes roles
        // that may no longer exist, and it is the cache an authorization check would believe.
        Artisan::call('optimize:clear');
        Artisan::call('permission:cache-reset');
        $this->line('  step 9  caches, config, routes, views and the permission cache cleared');

        // Step 10 — prove the money.
        [$proof, $moneyIsSound] = $this->proveTheMoney($integrity, $pending);

        // Step 11 — after-counts. Written whether or not the proof held: the diff is what tells an
        // operator how much of the ledger the restore rewound, and a failed restore needs it most.
        $this->captureAfterCounts($verification, $proof);

        if (! $moneyIsSound) {
            return $this->recordFailure(
                'The archive is in and the schema is forward, but the financial proof did not hold. '
                .'§6.10.5: the application stays down and the next step is the pre-restore copy.',
            );
        }

        return $this->recordCompletion($proof);
    }

    /*
    |--------------------------------------------------------------------------
    | The gates
    |--------------------------------------------------------------------------
    */

    /**
     * Gate: the archive exists, holds a database, completed, and its file is still on disk.
     *
     * Accepts an id or a uuid because an operator reading a notification has the uuid and an
     * operator reading the register has the id, and making them convert is how the wrong archive
     * gets chosen. A numeric string is looked up as an id first — ids are what the screens print.
     */
    private function resolveArchive(BackupPathResolver $paths): bool
    {
        $given = trim((string) $this->option('backup'));

        if ($given === '') {
            $this->error('--backup is required: name the archive to restore, by id or uuid.');
            $this->suggestArchives();

            return false;
        }

        $archive = ctype_digit($given)
            ? BackupRun::query()->whereKey((int) $given)->first()
            : null;

        $archive ??= BackupRun::query()->where('uuid', $given)->first();

        if ($archive === null) {
            $this->error(sprintf('No backup_runs row matches [%s].', $given));
            $this->suggestArchives();

            return false;
        }

        if (! $archive->type->includesDatabase()) {
            // A files-only archive holds no dump. Restoring one would be a no-op that reported
            // success, which is worse than a refusal because the operator would stop looking.
            $this->error(sprintf(
                'Archive #%d is a %s archive and holds no database dump. Restore the database archive of the same night.',
                $archive->id,
                $archive->type->value,
            ));

            return false;
        }

        if ($archive->status !== BackupStatus::Completed) {
            $this->error(sprintf(
                'Archive #%d is %s, not completed. %s',
                $archive->id,
                $archive->status->value,
                $archive->status->description(),
            ));

            return false;
        }

        if ($archive->file_pruned_at !== null) {
            // The row survives pruning by design (§6.10.3 rule 4), so a pruned archive still reads
            // like a perfectly good backup to anything that only looks at the status.
            $this->error(sprintf(
                'Archive #%d was pruned on %s. The row is still here; the file is not.',
                $archive->id,
                $archive->file_pruned_at->toDateString(),
            ));

            return false;
        }

        if (! $paths->exists($archive)) {
            $this->error(sprintf(
                'Archive #%d is recorded on disk [%s] at [%s], and it is not there.',
                $archive->id,
                $archive->disk,
                (string) $archive->path,
            ));

            return false;
        }

        $this->archive = $archive;

        return true;
    }

    private function resolveTarget(): bool
    {
        $given = trim((string) $this->option('target'));

        if ($given === '') {
            $this->error('--target is required: '.$this->targetList().'.');
            $this->line('  The target is not a label. It decides how many gates stand in front of you.');

            return false;
        }

        $target = RestoreTarget::tryFrom($given);

        if ($target === null) {
            $this->error(sprintf('Unknown target [%s]. Known targets: %s.', $given, $this->targetList()));

            return false;
        }

        /*
        | The Local exemption must not be reachable in production.
        |
        | `RestoreTarget::Local` skips the typed phrase and the pre-restore copy, and it is exempt
        | because on a developer's machine nothing is anybody's only copy. On a production host that
        | sentence is false, and `--target=local` becomes a one-word way to restore over the live
        | database with no phrase and no safety net — the exact bypass somebody reaches for when the
        | phrase will not match. Refused here, on the environment rather than on the database name,
        | because the bypass is the *target* and not the schema.
        */
        if ($target === RestoreTarget::Local && app()->environment('production')) {
            $this->error('--target=local is refused on a production install.');
            $this->line('  Local skips the confirmation phrase and the pre-restore backup, and it is allowed to');
            $this->line('  do that only because a developer machine has nothing to lose. APP_ENV here is');
            $this->line('  "production". If this really is the live database, say so: --target=production.');

            return false;
        }

        $this->target = $target;

        return true;
    }

    /**
     * Gate 4: a written reason, at least 20 characters of it.
     *
     * **A restore is the one operation whose "why" nobody can reconstruct afterwards, because the
     * evidence is what got replaced.** Every other question about a restore can be answered from the
     * row six months later; this one can only be answered by the person typing, now.
     */
    private function resolveReason(): bool
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('--reason is required, and there is no default for "why".');
            $this->line('  After this runs, the evidence of what went wrong is the database you just replaced.');
            $this->line('  Example: --reason="Fee receipts 8801-8840 were double-posted by the 14:20 import"');

            return false;
        }

        if (mb_strlen($reason) < self::REASON_MIN_LENGTH) {
            $this->error(sprintf(
                '--reason is %d characters; at least %d are required (the same rule the restore screen applies).',
                mb_strlen($reason),
                self::REASON_MIN_LENGTH,
            ));
            $this->line('  "testing" does not pass review, and neither does "restore".');

            return false;
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            // Refused rather than truncated: the column is 500 characters and a silently shortened
            // reason is a reason somebody else wrote.
            $this->error(sprintf(
                '--reason is %d characters; the column holds %d. Shorten it yourself rather than have it cut.',
                mb_strlen($reason),
                self::REASON_MAX_LENGTH,
            ));

            return false;
        }

        $this->reason = $reason;

        return true;
    }

    /**
     * Gate: which database is about to be overwritten, and is this target allowed to name it?
     *
     * The comparisons are case-insensitive throughout, for the reason
     * {@see BackupVerificationService} gives: MariaDB on Windows folds database names, so `MY_OFFICE`
     * and `my_office` are one schema, and a case-sensitive check here would be a check that passed
     * immediately before it destroyed production.
     */
    private function resolveDatabase(BackupPathResolver $paths): bool
    {
        $live = $paths->databaseName();
        $given = trim((string) $this->option('database'));
        $database = $given === '' ? $live : $given;

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            // The name reaches a `--database=` argument and a dozen printed lines. A value that is
            // not a plain identifier is a typo, and a typo here is a restore aimed somewhere else.
            $this->error(sprintf('[%s] is not a plain database identifier ([A-Za-z0-9_], up to 64).', $database));

            return false;
        }

        $isLive = mb_strtolower($database) === mb_strtolower($live);

        /*
        | --database must not be the live database unless the target says so.
        |
        | `production` exists in order to name it; `local` names it because on a developer machine the
        | live database *is* the local one (and §6.10.5's Local exemption is already fenced off from
        | production hosts in resolveTarget()). `staging` naming the live database is the accident
        | this branch exists for: a staging runbook pasted onto a production console, which reads as
        | a rehearsal right up to the moment it is not.
        */
        if ($isLive && $this->target === RestoreTarget::Staging) {
            $this->error(sprintf(
                '--target=staging with --database=%s: that is the database this application is connected to.',
                $database,
            ));
            $this->line('  Either name the staging schema, or say --target=production and pass its gates.');

            return false;
        }

        if (! $isLive && $this->target === RestoreTarget::Production) {
            $this->error(sprintf(
                '--target=production restores the live database [%s]; you named [%s].',
                $live,
                $database,
            ));
            $this->line('  A production restore that quietly wrote somewhere else would report success and fix');
            $this->line('  nothing. Drop --database, or pick the target that matches where you are pointing.');

            return false;
        }

        $scratch = setting('backup.restore_scratch_database', 'my_office_restore_test');

        if (is_string($scratch) && mb_strtolower(trim($scratch)) === mb_strtolower($database)) {
            // The weekly deep verification creates and DROPs this schema without asking anybody
            // (§6.10.4). A restore that landed there would be dropped at 04:30 on Sunday, and the
            // operator would be left holding a `completed` row pointing at nothing.
            $this->error(sprintf(
                '[%s] is backup.restore_scratch_database. The weekly restore proof drops that schema without warning.',
                $database,
            ));

            return false;
        }

        $this->database = $database;
        $this->replacesOwnDatabase = $isLive;

        return true;
    }

    /**
     * Gate 2, the half that can be answered before anything is opened: has this archive ever been
     * proved at all?
     *
     * **Restoring an unverified archive is how a bad backup becomes a bad production database.** An
     * archive whose `verification_status` is `unverified` has never been hashed and never been
     * opened; one that is `failed` has been proved *not* to be an archive. Both are refused outright
     * here, before maintenance mode, before the safety copy, with the command that fixes it — a
     * refusal that can be resolved in one paste is a refusal an operator will accept.
     */
    private function assertArchiveWasProved(): bool
    {
        if ($this->archive->verification_status->checksumProved()) {
            if (! $this->archive->verification_status->satisfiesGoLive() && $this->target->requiresPreBackup()) {
                // `checksum_ok` says the bytes are the bytes that were written. It says nothing about
                // whether the dump inside loads. Not a refusal — in a real recovery the newest
                // archive may only ever have been hashed, and a command that refused it would be a
                // command that stood between an operator and their only copy.
                $this->warn(sprintf(
                    '  Archive #%d is %s, never %s: nobody has established that this dump loads.',
                    $this->archive->id,
                    $this->archive->verification_status->value,
                    'restore_ok',
                ));
            }

            return true;
        }

        $this->error(sprintf(
            'Archive #%d has never been proved: verification_status is "%s".',
            $this->archive->id,
            $this->archive->verification_status->value,
        ));
        $this->line('  '.$this->archive->verification_status->description());
        $this->newLine();
        $this->line('  Prove it first, then come back:');
        $this->line(sprintf('    php artisan backup:verify --backup=%d          (checksum)', $this->archive->id));
        $this->line(sprintf('    php artisan backup:verify --backup=%d --deep   (it actually restores)', $this->archive->id));

        return false;
    }

    /**
     * Gate: no dump may be in flight.
     *
     * A backup that started a minute ago is reading the database this command is about to replace,
     * and the archive it produces would be half of each — a file that looks like a backup, verifies
     * like a backup, and restores to nothing anybody can use.
     */
    private function assertNoBackupInFlight(BackupService $backups): bool
    {
        if (! $backups->isRunning()) {
            return true;
        }

        $this->error('A backup is running right now. It would capture a database halfway through being replaced.');
        $this->line('  Wait for it to finish — the register shows it — and run this again.');

        return false;
    }

    /**
     * Gate 3: the typed confirmation phrase, compared byte for byte.
     *
     * **A confirmation somebody can click through without reading is not a confirmation.** So the
     * phrase carries the database name and today's date: it cannot be kept in shell history, it
     * cannot be pasted from last week's runbook, and typing it requires having read which database
     * is about to be overwritten. The comparison is `hash_equals` for exactness rather than for
     * secrecy — the phrase is printed in the refusal on purpose, because the alternative to telling
     * the operator what to type is the operator guessing.
     *
     * @return CarbonInterface|false|null the stamp for `confirmed_at`, null when the target needs
     *                                    no phrase, false when the phrase was wrong
     */
    private function assertConfirmationPhrase(): CarbonInterface|false|null
    {
        if (! $this->target->requiresConfirmationPhrase()) {
            return null;
        }

        $expected = self::phrase($this->database);
        $given = (string) $this->option('confirm');

        if ($given !== '' && hash_equals($expected, $given)) {
            return Carbon::now();
        }

        $this->error($given === ''
            ? '--confirm is required for a '.$this->target->value.' restore.'
            : 'The confirmation phrase does not match. It is compared exactly, capital letters included.');

        $this->newLine();
        $this->line('  You are about to overwrite <options=bold>'.$this->database.'</> with archive #'.$this->archive->id.'.');
        $this->line('  Type this, exactly:');
        $this->newLine();
        $this->line('    --confirm="'.$expected.'"');
        $this->newLine();
        $this->line('  The date is today\'s, so a phrase copied from a runbook will not work — that is the point.');

        return false;
    }

    /**
     * `backup.restore_confirmation_phrase` with `{database}` and `{date}` substituted.
     *
     * Static and public so the restore wizard (§8.3) renders the identical string. Two
     * implementations of this substitution would differ on the day somebody changed the template,
     * and the operator's conclusion would be that the console is broken.
     */
    public static function phrase(string $database, ?CarbonInterface $at = null): string
    {
        $template = setting('backup.restore_confirmation_phrase', 'RESTORE {database} {date}');
        $template = is_string($template) && trim($template) !== '' ? trim($template) : 'RESTORE {database} {date}';

        return str_replace(
            ['{database}', '{date}'],
            [$database, ($at === null ? Carbon::now() : Carbon::instance($at))->format(self::PHRASE_DATE_FORMAT)],
            $template,
        );
    }

    /**
     * Who is `requested_by` — the one NOT NULL, RESTRICT foreign key on the row.
     *
     * **A row saying production was overwritten on a Tuesday with nobody attached to it is not an
     * audit trail, it is an alibi** ({@see RestoreRecord}). A console has no session, so the actor
     * cannot be authenticated here; what it can be is *unambiguous*. With exactly one Super Admin
     * on the install there is exactly one person who could have been authorised to do this, and
     * naming them is a statement about authority rather than about keystrokes — which is what `notes`
     * then says, in as many words. With several, the command refuses rather than pick one: attributing
     * a production restore to whichever Super Admin has the lowest id is fabrication, and the restore
     * screen (§8.3) knows exactly who is typing.
     */
    private function resolveActor(): bool
    {
        $authenticated = auth()->user();

        if ($authenticated instanceof User) {
            $this->actor = $authenticated;

            return true;
        }

        try {
            $candidates = User::query()->role('Super Admin')->orderBy('id')->get();
        } catch (Throwable $exception) {
            $this->error('The Super Admin role could not be read, so this restore cannot be attributed: '.$this->short($exception));

            return false;
        }

        if ($candidates->count() === 1) {
            /** @var User $only */
            $only = $candidates->first();
            $this->actor = $only;

            $this->warn(sprintf(
                '  Attributed to %s (#%d) — the only Super Admin on this install.',
                (string) $only->email,
                (int) $only->id,
            ));
            $this->line('  A console restore cannot prove who typed it. The restore screen can.');

            return true;
        }

        $this->error($candidates->isEmpty()
            ? 'There is no Super Admin to attribute this restore to, and requested_by is NOT NULL.'
            : sprintf('There are %d Super Admins; this command will not pick one for you.', $candidates->count()));
        $this->line('  requested_by answers "who overwrote the database" and it is not a field to guess at.');
        $this->line('  Run the restore from Admin > Backups > Restore, where the actor is the person signed in.');

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | The steps
    |--------------------------------------------------------------------------
    */

    /**
     * Step 1 — the same three facts the screen shows: which database, which archive, how old.
     */
    private function announce(): void
    {
        $age = $this->archive->finished_at ?? $this->archive->started_at ?? $this->archive->created_at;

        $this->newLine();
        $this->line('  <options=bold;fg=red>RESTORE</> — '.$this->target->label().' · '.$this->target->description());
        $this->newLine();
        $this->table([], [
            ['database', $this->database.($this->replacesOwnDatabase ? '  (this application\'s own database)' : '  (a side database)')],
            ['archive', sprintf('#%d %s', $this->archive->id, (string) ($this->archive->filename ?? '(no filename)'))],
            ['uuid', (string) $this->archive->uuid],
            ['taken', $age === null ? 'unknown' : sprintf('%s (%s)', $age->toDateTimeString(), $age->diffForHumans())],
            ['size', $this->archive->size_bytes === null ? 'unknown' : sprintf('%.1f MB', $this->archive->size_bytes / 1048576)],
            ['checksum', mb_substr((string) $this->archive->checksum_sha256, 0, 32).'…'],
            ['verified', $this->archive->verification_status->label().($this->archive->verified_at === null ? '' : ' on '.$this->archive->verified_at->toDateTimeString())],
            ['encrypted', $this->archive->is_encrypted ? 'yes' : 'no'],
            ['reason', $this->reason],
            ['requested by', (string) $this->actor->email],
        ]);
        $this->newLine();
    }

    /**
     * Step 2 — re-hash the file now.
     *
     * The stored `verification_status` says the archive was an archive when somebody last looked.
     * Disks rot, offsite syncs truncate, and the gap between "verified on Sunday" and "restored on
     * Thursday" is where a silently corrupted file lives. This is one hash of one file against a
     * column, and it is the cheapest gate in the list.
     */
    private function verifyArchiveNow(BackupVerificationService $verification): bool
    {
        $verified = false;

        $this->components->task('  step 2  re-verifying the archive on disk', function () use ($verification, &$verified): bool {
            $this->archive = $verification->verifyChecksum($this->archive);
            $verified = $this->archive->verification_status->checksumProved();
            $this->checksumVerified = $verified;

            return $verified;
        });

        if (! $verified) {
            $this->error('  '.(string) $this->archive->verification_notes);
        }

        return $verified;
    }

    /**
     * Step 3 — maintenance mode, with the bypass secret so the operator can still watch.
     *
     * Skipped for `local`, where there is no audience. A write that arrives from a panel while the
     * dump is going in lands in a database that is halfway between two snapshots and is then
     * overwritten by the rest of the dump — a lost write nobody will ever find, because the request
     * returned 200.
     */
    private function enterMaintenance(): bool
    {
        if ($this->target === RestoreTarget::Local) {
            return true;
        }

        $secret = setting('ops.maintenance_secret');
        $arguments = ['--render' => 'errors::503'];

        if (is_string($secret) && trim($secret) !== '') {
            $arguments['--secret'] = trim($secret);
        }

        try {
            Artisan::call('down', $arguments);
        } catch (Throwable $exception) {
            $this->error('  step 3  maintenance mode failed: '.$this->short($exception));

            return false;
        }

        $this->broughtDown = true;
        $this->line('  step 3  the site and all five panels are returning 503');

        if (! isset($arguments['--secret'])) {
            $this->warn('          ops.maintenance_secret is empty, so there is no bypass URL: you cannot');
            $this->warn('          check the restored application before letting everybody back in.');
        }

        return true;
    }

    /**
     * Step 4 — the queue worker.
     *
     * PHP cannot stop an nssm service or a supervisor group portably, and a command that pretended
     * to would be worse than one that asks. A worker still running during the swap writes commission
     * rows into a database that is being replaced under it: the job is marked done, the row it wrote
     * is gone, and the ledger is short one entry with nothing anywhere to say so.
     */
    private function confirmWorkerIsStopped(): bool
    {
        $this->line('  step 4  the queue worker must be stopped before the swap:');
        $this->line('            Windows  nssm stop MyOfficeQueue');
        $this->line('            Linux    supervisorctl stop myoffice-queue:*');

        if (! $this->input->isInteractive()) {
            $this->warn('          --no-interaction: taking the runbook\'s word for it. A worker still running now');
            $this->warn('          will write rows into a database that is being replaced under it.');

            return true;
        }

        return $this->confirm('  Is the worker stopped?', false);
    }

    /**
     * Step 5 — the pre-restore copy.
     *
     * **Restoring over production without one means the state you just replaced is gone, and there
     * is no undo for an undo.** Which is why a failure here aborts rather than warns: the operator
     * came to fix a problem, and a restore with no way back can only ever turn one problem into two.
     * Required for every target except `local` ({@see RestoreTarget::requiresPreBackup()}), written
     * that way round so a target added later is guarded by default.
     */
    private function takePreRestoreBackup(BackupService $backups): bool
    {
        if (! $this->target->requiresPreBackup()) {
            $this->line('  step 5  skipped: a local restore takes no safety copy ('.RestoreTarget::Local->description().')');

            return true;
        }

        $this->line('  step 5  taking the pre-restore backup — this is the way back');

        try {
            $pre = $backups->run(
                BackupType::Database,
                BackupTrigger::PreRestore,
                sprintf('Pre-restore copy before restoring archive #%d: %s', $this->archive->id, $this->reason),
                $this->actor,
            );
        } catch (Throwable $exception) {
            $this->error('          the pre-restore backup failed: '.$this->short($exception));
            $this->line('          Nothing has been restored. Fix the backup path first — a restore with no way');
            $this->line('          back is not a recovery, it is a second incident.');

            return false;
        }

        if ($pre->status !== BackupStatus::Completed) {
            $this->error('          the pre-restore backup is '.$pre->status->value.', not completed.');

            return false;
        }

        $this->preBackup = $pre;
        $this->info(sprintf('          pre-restore archive #%d: %s', $pre->id, (string) $pre->filename));

        return true;
    }

    /**
     * Step 6 — the before-counts, over §6.10.4's proof list.
     *
     * Captured now and never again: `row_counts_before` and `ledger_rows_before` are outside
     * {@see RestoreRecord::MUTABLE_COLUMNS}, because a "before" figure that can be rewritten
     * afterwards is not a before figure.
     */
    private function captureBeforeCounts(BackupVerificationService $verification): void
    {
        if (! $this->replacesOwnDatabase) {
            $this->line('  step 6  skipped: the counts would be read from this application\'s database, not from '.$this->database);

            return;
        }

        try {
            $this->countsBefore = $verification->proofFigures($this->database);
            $this->ledgerBefore = $this->countsBefore[BackupVerificationService::LEDGER_TABLE] ?? null;
            $this->ledgerBefore = $this->ledgerBefore === -1 ? null : $this->ledgerBefore;

            $this->line(sprintf(
                '  step 6  before-counts captured over %d proof tables; ledger holds %s rows',
                count($this->countsBefore),
                $this->ledgerBefore === null ? 'an unknown number of' : number_format($this->ledgerBefore),
            ));
        } catch (Throwable $exception) {
            // Not fatal. A count that could not be read is recorded as absent, and the restore is
            // still the right thing to do; a recovery blocked by a failed SELECT COUNT(*) would be
            // an absurd place to stop.
            $this->warn('  step 6  the before-counts could not be read: '.$this->short($exception));
        }
    }

    /**
     * Step 7a — the dump, out of the archive and onto local disk.
     *
     * Deliberately before the row flips to `running`: nothing has been written to the target yet, so
     * a corrupt zip or a wrong archive password records as `Aborted` and the operator knows the
     * database is untouched. The entry name is read out of the archive rather than assumed, because
     * an archive taken when the database had another name still has to restore.
     */
    private function extractDump(BackupPathResolver $paths): string
    {
        $workspace = $this->workspace = $paths->workspace('restore-'.($this->restore?->uuid ?? 'pending'));
        $path = $paths->materialize($this->archive, $workspace);

        if ($path === null) {
            throw new RuntimeException('the archive is not on disk.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('the archive would not open.');
        }

        try {
            if ($this->archive->is_encrypted) {
                $password = setting('backup.archive_password');

                if (! is_string($password) || $password === '') {
                    throw new RuntimeException(
                        'this archive is encrypted and backup.archive_password is empty. The password that was '
                        .'set when it was written is the only thing that opens it.'
                    );
                }

                if (! $zip->setPassword($password)) {
                    throw new RuntimeException('the archive is encrypted and the stored password was refused.');
                }
            }

            $entry = $this->dumpEntryIn($zip, $paths);

            if ($entry === null) {
                throw new RuntimeException('the archive holds no database dump.');
            }

            if (! $zip->extractTo($workspace, [$entry])) {
                throw new RuntimeException('the dump could not be extracted'
                    .($this->archive->is_encrypted ? ' — the archive password may not be the one it was written with.' : '.'));
            }
        } finally {
            $zip->close();
        }

        $extracted = $workspace.'/'.$entry;

        if (! is_file($extracted) || filesize($extracted) === 0) {
            throw new RuntimeException('the extracted dump is empty.');
        }

        $this->line(sprintf('  step 7  dump extracted, %.1f MB', (int) filesize($extracted) / 1048576));

        return $extracted;
    }

    private function dumpEntryIn(ZipArchive $zip, BackupPathResolver $paths): ?string
    {
        $expected = $paths->dumpEntry((string) ($this->archive->database_name ?? $paths->databaseName()));

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
     * Step 7b — feed the dump to the `mysql` client on stdin.
     *
     * A stream, not a string: a dump is measured in hundreds of megabytes. Stdin rather than the
     * client's `SOURCE` builtin, which takes the rest of the line as a literal filename — and every
     * path on this host contains a space (T2). The credentials go in a `--defaults-extra-file` and
     * never onto the command line, where `ps` and the Windows process list would publish them.
     *
     * The credentials are the **DDL** connection's (D58, §6.9.3): a dump contains `CREATE TABLE`,
     * `CREATE TRIGGER` and `CREATE ROUTINE`, and §6.9.3 grants the runtime user none of the three.
     * A restore attempted as the runtime user fails at the first statement — and would have passed
     * on a developer's XAMPP root account, which is exactly why the separation only ever bites in
     * production.
     */
    private function loadDump(string $dump): void
    {
        $connection = $this->migrationConnection();
        $config = (array) config('database.connections.'.$connection);
        $binary = $this->mysqlBinary();

        $defaults = $this->writeDefaultsFile(dirname($dump), $config);
        $handle = fopen($dump, 'rb');

        if ($handle === false) {
            throw new RuntimeException('the extracted dump could not be opened.');
        }

        try {
            $result = Process::timeout(self::LOAD_TIMEOUT_SECONDS)
                ->input($handle)
                ->run([
                    $binary,
                    '--defaults-extra-file='.$defaults,
                    '--database='.$this->database,
                ]);

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'the mysql client exited %d: %s',
                    $result->exitCode() ?? -1,
                    mb_substr(trim($result->errorOutput()), 0, 800),
                ));
            }
        } finally {
            fclose($handle);

            if (is_file($defaults)) {
                @unlink($defaults);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeDefaultsFile(string $directory, array $config): string
    {
        $path = $directory.'/my.cnf';
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

            // MySQL option files take backslash escapes inside a double-quoted value. A password
            // containing a quote would otherwise end the value early and the client would
            // authenticate with half of it — which fails in a way that reads like a wrong password.
            $lines[] = $option.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        }

        if (file_put_contents($path, implode("\n", $lines)."\n", LOCK_EX) === false) {
            throw new RuntimeException('the defaults file for the mysql client could not be written.');
        }

        @chmod($path, 0600);

        return $path;
    }

    private function mysqlBinary(): string
    {
        $configured = setting('backup.mysql_path');
        $path = is_string($configured) ? trim($configured) : '';

        if ($path === '') {
            throw new RuntimeException('backup.mysql_path is empty, and a restore is the mysql client.');
        }

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('the mysql client at "%s" does not exist (backup.mysql_path).', $path));
        }

        if (DIRECTORY_SEPARATOR === '/' && ! is_executable($path)) {
            throw new RuntimeException(sprintf('the mysql client at "%s" is not executable.', $path));
        }

        return $path;
    }

    /**
     * The connection whose credentials may issue DDL (D58, §6.9.3, D168).
     *
     * `mysql_migration` when it is configured, the default connection otherwise — the same
     * resolution {@see BackupVerificationService} uses, and for the same reason: falling back keeps
     * a single-user installation working, because a restore run as the application user is a
     * restore, and no restore at all is not.
     */
    private function migrationConnection(): string
    {
        return is_array(config('database.connections.mysql_migration'))
            ? 'mysql_migration'
            : (string) config('database.default');
    }

    /**
     * Step 8's acceptance criterion, counted the same way {@see BackupVerificationService} counts it:
     * migration files on disk that the restored `migrations` table has never heard of.
     */
    private function pendingMigrations(): int
    {
        $files = glob(database_path('migrations/*.php'));
        $files = $files === false ? [] : $files;

        $ran = DB::table((string) config('database.migrations.table', 'migrations'))
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

    /**
     * Step 10 — prove the money, through the suites the spine and Phase 12 own.
     *
     * `constraints` then `wallet`, never `--repair`: drift is reported and never auto-repaired
     * (spine §6.5.4), because a restore that quietly corrected the ledger it had just rewound would
     * destroy the only evidence of what the restore cost. A **failed** suite fails the restore; a
     * **warning** does not, but it is printed loudly and stored in `proof`, because a warning on a
     * financial suite still blocks go-live ({@see IntegrityCheckStatus::blocksGoLive()}).
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function proveTheMoney(IntegrityCheckService $integrity, int $pending): array
    {
        $sound = true;

        /** @var array<string, array<string, mixed>> $results */
        $results = [];

        foreach ([IntegrityCheckSuite::Constraints, IntegrityCheckSuite::Wallet] as $suite) {
            try {
                $run = $integrity->run($suite, ['scope' => 'all', 'trigger' => 'restore']);
            } catch (Throwable $exception) {
                // A proof that could not run is not a proof that passed. This is the one place where
                // "we could not check" and "it failed" have to be treated identically.
                $this->error(sprintf('  step 10 the %s suite could not run: %s', $suite->value, $this->short($exception)));
                $results[$suite->value] = ['status' => 'errored', 'error' => $this->short($exception)];
                $sound = false;

                continue;
            }

            $results[$suite->value] = [
                'status' => $run->status->value,
                'run' => $run->run_uuid,
                'checked' => $run->checks_total,
                'drift' => $run->checks_warned,
                'failed' => $run->checks_failed,
            ];

            $this->renderSuite($run);

            if ($run->status === IntegrityCheckStatus::Failed) {
                $sound = false;
            }
        }

        /*
        | The shape §2.2 documents for this column — `{constraints: pass/fail, reconciliation:
        | {checked, drift, failed}, migrations_pending: n}` — plus the two run uuids, so the row can
        | be joined back to the `integrity_check_runs` evidence instead of merely asserting it.
        | `constraints` is pass/fail and not the suite's own status on purpose: a warning on the
        | constraints suite is not a pass, and this key is the one a reader trusts at a glance.
        */
        $constraints = $results[IntegrityCheckSuite::Constraints->value]['status'] ?? 'errored';

        return [[
            'constraints' => $constraints === IntegrityCheckStatus::Passed->value ? 'pass' : 'fail',
            'reconciliation' => $results[IntegrityCheckSuite::Wallet->value] ?? null,
            'migrations_pending' => $pending,
            'suites' => $results,
        ], $sound];
    }

    private function renderSuite(IntegrityCheckRun $run): void
    {
        $line = sprintf('  step 10 %-12s %s', $run->suite->value, $run->status->label());

        match ($run->status) {
            IntegrityCheckStatus::Passed => $this->info($line),
            IntegrityCheckStatus::Warning => $this->warn($line.' — drift is reported, never repaired here'),
            IntegrityCheckStatus::Failed => $this->error($line),
        };

        foreach (array_slice($run->findings ?? [], 0, 5) as $finding) {
            $this->line('            '.mb_substr((string) ($finding['actual'] ?? json_encode($finding)), 0, 110));
        }
    }

    /**
     * Step 11 — the after-counts, and the diff an operator reads first.
     *
     * A negative ledger delta is legitimate — restoring an older archive loses the rows written
     * since — and it must never happen without somebody having seen the number.
     *
     * @param  array<string, mixed>  $proof
     */
    private function captureAfterCounts(BackupVerificationService $verification, array &$proof): void
    {
        if (! $this->replacesOwnDatabase || $this->restore === null) {
            return;
        }

        try {
            $after = $verification->proofFigures($this->database);
            $ledgerAfter = $after[BackupVerificationService::LEDGER_TABLE] ?? null;
            $ledgerAfter = $ledgerAfter === -1 ? null : $ledgerAfter;

            $this->transition($this->restore->status, [
                'row_counts_after' => $after,
                'ledger_rows_after' => $ledgerAfter,
            ]);

            $proof['table_count_after'] = $verification->tableCount($this->database);

            $this->renderDiff($this->countsBefore ?? [], $after);
        } catch (Throwable $exception) {
            $this->warn('  step 11 the after-counts could not be read: '.$this->short($exception));
        }
    }

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    private function renderDiff(array $before, array $after): void
    {
        $rows = [];

        foreach ($after as $table => $count) {
            $was = $before[$table] ?? null;
            $delta = $was === null || $was < 0 || $count < 0 ? null : $count - $was;

            if ($delta === 0) {
                continue;
            }

            $rows[] = [
                $table,
                $was === null ? '?' : ($was < 0 ? 'missing' : number_format($was)),
                $count < 0 ? '<fg=red>missing</>' : number_format($count),
                $delta === null ? '?' : sprintf($delta < 0 ? '<fg=yellow>%+d</>' : '%+d', $delta),
            ];
        }

        $this->newLine();

        if ($rows === []) {
            $this->info('  step 11 every proof table came back with the row count it went in with.');

            return;
        }

        $this->line('  step 11 what the restore changed:');
        $this->table(['table', 'before', 'after', 'delta'], $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | The row
    |--------------------------------------------------------------------------
    */

    /**
     * Insert the `backup_restores` row, at the first moment every immutable column is known.
     *
     * Why not earlier: `pre_restore_backup_run_id`, `row_counts_before` and `ledger_rows_before` are
     * all outside {@see RestoreRecord::MUTABLE_COLUMNS}, so an INSERT before step 6 could never have
     * them, and a later UPDATE would throw. Why not later: the next thing that happens is a write to
     * the target database, and a restore that began without a row is a restore nobody can prove.
     */
    private function recordRequested(): void
    {
        $this->insertRow(RestoreStatus::Requested);

        $this->line('  ------- backup_restores row '.$this->restore?->uuid.' recorded as requested');
    }

    /**
     * The one place a `backup_restores` row is created — for the happy path at step 6, and for an
     * abort that refused before step 6 and therefore has nothing to advance.
     *
     * **Every immutable column is written here or never.** That is not a style choice: §2.2 keeps
     * `target`, `database_name`, `reason`, `requested_by`, both backup references and the two
     * "before" figures outside the update whitelist, because editing any of them is precisely what
     * somebody would do about a restore that should not have happened.
     *
     * @param  array<string, mixed>  $extra
     */
    private function insertRow(RestoreStatus $status, array $extra = []): void
    {
        $restore = new RestoreRecord;

        $attributes = array_merge([
            'uuid' => (string) Str::ulid(),
            'backup_run_id' => $this->archive->id,
            'pre_restore_backup_run_id' => $this->preBackup?->id,
            'target' => $this->target,
            'status' => $status,
            'database_name' => $this->database,
            'reason' => $this->reason,
            'requested_by' => $this->actor->id,
            'confirmed_at' => $this->confirmedAt,
            // Never stamped by this command. See the class note: there is no session in a console to
            // re-authenticate against, and a fabricated stamp makes every genuine one worthless.
            'password_confirmed_at' => null,
            // Whatever is actually true right now. An abort at step 2 records `false` here, and that
            // false is the whole explanation of the row.
            'checksum_verified' => $this->checksumVerified,
            'row_counts_before' => $this->countsBefore,
            'ledger_rows_before' => $this->ledgerBefore,
            'notes' => $this->consoleNote(),
        ], $extra);

        /*
        | The database's own rule about this row, honoured here rather than discovered by a rejected
        | INSERT.
        |
        | `chk_brs_window` refuses `finished_at` without `started_at`, and the only caller that
        | arrives here with `finished_at` set is an abort that refused before step 7b ever stamped a
        | start. Without this line that INSERT throws, {@see recordAbort()} catches it, and the
        | refusal is recorded nowhere but the console — which is the failure this whole table exists
        | to prevent. Filled from the attempt's own clock, never from `now()`: `duration_seconds` is
        | derived from the same figure, and a start invented at write time would report every refusal
        | as having taken zero seconds.
        */
        if (($attributes['finished_at'] ?? null) !== null && ($attributes['started_at'] ?? null) === null) {
            $attributes['started_at'] = $this->attemptStartedAt;
        }

        $restore->fill($attributes);

        // Outside $fillable on purpose, and null in console because Blameable reads the session. The
        // row is not half-attributed: `requested_by` is the answer to "who did this" and it is set.
        $restore->created_by = $this->actor->id;

        DB::transaction(static function () use ($restore): void {
            $restore->save();
        });

        $this->restore = $restore->refresh();
        $this->refreshEvidence();
    }

    /**
     * What `notes` says about a console restore, in the row itself rather than in somebody's memory.
     *
     * The null `password_confirmed_at` will be the first thing a reviewer questions, so the row
     * answers the question before it is asked.
     */
    private function consoleNote(): string
    {
        return mb_substr(sprintf(
            'Console restore via backup:restore. password.confirm cannot be honoured in a console, so '
            .'password_confirmed_at is null and the typed phrase is the gate that stood here. Actor %s '
            .'resolved as %s.',
            (string) $this->actor->email,
            auth()->id() === null ? 'the only Super Admin on this install' : 'the signed-in user',
        ), 0, 500);
    }

    /**
     * Re-read the three rows the evidence file carries, straight from the database.
     *
     * **Raw rows rather than model attributes, deliberately.** After a `forceFill()->save()` the
     * model holds a `RestoreStatus` instance and a `Carbon`, and neither can be handed back to an
     * INSERT — {@see reinstateEvidence()} would fail on the one path where it is the last copy of the
     * record. What the database just returned is by definition what the database will accept back.
     */
    private function refreshEvidence(): void
    {
        if ($this->restore === null) {
            return;
        }

        $this->restore = $this->restore->refresh();

        $this->evidence = [
            'restore' => $this->rawRow('backup_restores', (int) $this->restore->id),
            'archive' => $this->rawRow('backup_runs', (int) $this->archive->id),
            'pre_restore' => $this->preBackup === null ? null : $this->rawRow('backup_runs', (int) $this->preBackup->id),
        ];

        // Only rewritten once the file already exists: the first write is `writeEvidence()`'s, and it
        // is the one that prints the path.
        if ($this->evidenceFile !== null) {
            $this->writeEvidence();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawRow(string $table, int $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Move the row along, through the whitelist and nothing else.
     *
     * `forceFill` because `status` and the lifecycle stamps are not all in `$fillable`, and the model's
     * `updating` guard is what actually enforces §2.2 — bypassing mass assignment does not bypass it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(RestoreStatus $status, array $attributes = []): void
    {
        if ($this->restore === null) {
            return;
        }

        $restore = $this->restore;

        /*
        | Same `chk_brs_window` rule as {@see insertRow()}, on the other path to a terminal row.
        |
        | A restore that was already `requested` and then aborted — the dump would not come out of
        | the archive, say — has a row but no `started_at`, so the UPDATE that closes it would be
        | refused by the constraint, be swallowed by the catch below, and leave the row sitting at
        | `requested` for ever. A register showing a restore that is still in flight three weeks
        | later is worse than one showing an abort: somebody will go looking for a process that
        | stopped before it started.
        */
        if (($attributes['finished_at'] ?? null) !== null
            && $restore->started_at === null
            && ! array_key_exists('started_at', $attributes)) {
            $attributes['started_at'] = $this->attemptStartedAt;
        }

        try {
            DB::transaction(static function () use ($restore, $status, $attributes): void {
                $restore->forceFill(array_merge(['status' => $status], $attributes))->save();
            });
        } catch (Throwable $exception) {
            // The row may have been deleted by the very dump this restore loaded, in which case an
            // UPDATE affects nothing and reports success; a throw here is the other case, a column
            // outside MUTABLE_COLUMNS. Either way the console and the evidence file remain, and the
            // restore is not abandoned over its own bookkeeping.
            Log::error('The backup_restores row could not be advanced.', [
                'restore' => $restore->uuid,
                'status' => $status->value,
                'exception' => $exception::class,
                'message' => $this->short($exception),
            ]);

            $this->warn('  ------- the restore row could not be updated: '.$this->short($exception));
            $this->warn('          the evidence file is '.($this->evidenceFile ?? 'not written'));
        }
    }

    /**
     * Copy the row out of the database, before the database is replaced.
     *
     * **Evidence that lives only in the thing being replaced is not evidence.** `backup_restores` is
     * inside the dump unless somebody has put it on `backup.excluded_tables`, so in a few seconds
     * this row will be whatever it was on the night the archive was taken — which is to say, absent.
     * The file lands under `storage/app/backups`, which is private, is excluded from *file* backups,
     * and is untouched by a database restore, and its path is printed whatever happens next.
     */
    private function writeEvidence(): void
    {
        if ($this->restore === null) {
            return;
        }

        $directory = storage_path('app/'.BackupPathResolver::DEFAULT_DISK.'/'.self::EVIDENCE_DIRECTORY);

        try {
            File::ensureDirectoryExists($directory, 0700);

            $path = $directory.'/'.$this->restore->uuid.'.json';

            $written = file_put_contents($path, (string) json_encode([
                'written_at' => Carbon::now()->toIso8601String(),
                'why_this_file_exists' => 'A restore overwrites the table it is recorded in. This is the copy '
                    .'that survives it (phase-24-25 §6.10.5 step 13).',
                'command' => 'backup:restore',
                'actor' => ['id' => $this->actor->id, 'email' => (string) $this->actor->email],
                'rows' => $this->evidence,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

            if ($written === false) {
                throw new RuntimeException('file_put_contents returned false.');
            }

            @chmod($path, 0600);

            $this->evidenceFile = $path;
            $this->line('  ------- evidence written to '.$path);
        } catch (Throwable $exception) {
            // Not fatal, and loudly so. A recovery does not stop because a log file could not be
            // written; but the operator is told, now, that the audit trail is one dump away from
            // being gone.
            $this->warn('  ------- the evidence file could not be written: '.$this->short($exception));
            $this->warn('          if this restore replaces backup_restores, its row will not survive.');
        }
    }

    /**
     * Put the row back if the dump deleted it.
     *
     * The FK order is forced: `backup_restores` RESTRICTs onto two `backup_runs` rows and onto a
     * `users` row, so the parents go first and the whole thing is skipped when the actor is not in
     * the restored snapshot — a user created after the archive was taken simply is not there, and an
     * insert that violated the constraint would abort a restore that had already succeeded.
     *
     * Raw inserts rather than the models: these rows are being *reinstated*, not created, and the
     * model events would restamp `created_by`, re-cast the timestamps and fire observers over a
     * database that has just been swapped underneath them.
     */
    private function reinstateEvidence(): void
    {
        if ($this->restore === null || ! $this->replacesOwnDatabase || $this->evidence === []) {
            return;
        }

        $uuid = (string) $this->restore->uuid;

        try {
            if (RestoreRecord::query()->where('uuid', $uuid)->exists()) {
                // The dump did not carry this table, or carried a snapshot that already held the row.
                // Either way the row we have been writing to is still the row on disk.
                $this->restore = RestoreRecord::query()->where('uuid', $uuid)->firstOrFail();

                return;
            }

            $this->warn('  ------- the restore row was inside the dump and is gone; putting it back');

            $archiveId = $this->reinstateBackupRun($this->evidence['archive'] ?? null);
            $preId = $this->reinstateBackupRun($this->evidence['pre_restore'] ?? null);

            /** @var array<string, mixed>|null $row */
            $row = $this->evidence['restore'] ?? null;

            if ($row === null || $archiveId === null || ! DB::table('users')->where('id', $row['requested_by'] ?? 0)->exists()) {
                throw new RuntimeException(
                    'the parent rows the restore record points at are not in the restored snapshot, so the '
                    .'row cannot be re-inserted without breaking its foreign keys.'
                );
            }

            unset($row['id']);
            $row['backup_run_id'] = $archiveId;
            $row['pre_restore_backup_run_id'] = $preId;

            DB::table('backup_restores')->insert($row);

            $this->restore = RestoreRecord::query()->where('uuid', $uuid)->firstOrFail();
            $this->info('  ------- restore row '.$uuid.' re-inserted after the swap');
        } catch (Throwable $exception) {
            Log::error('The backup_restores row could not be reinstated after the restore.', [
                'restore' => $uuid,
                'evidence_file' => $this->evidenceFile,
                'exception' => $exception::class,
                'message' => $this->short($exception),
            ]);

            $this->error('  ------- the restore row could not be put back: '.$this->short($exception));
            $this->error('          THE AUDIT TRAIL FOR THIS RESTORE IS THE FILE AT:');
            $this->error('          '.($this->evidenceFile ?? '(none — it could not be written either)'));
            $this->line('          Consider adding backup_runs and backup_restores to backup.excluded_tables so');
            $this->line('          a restore stops rewinding its own history.');

            /*
            | Dropped on purpose, and this is the important line in the method.
            |
            | The model still believes it exists at the id it was inserted at, and that id now belongs
            | to whichever restore the *restored snapshot* had there. Every later `transition()` would
            | issue `UPDATE backup_restores SET status = ... WHERE id = <that id>` and quietly rewrite
            | somebody else's history — turning a failure to record this restore into a falsification
            | of another one. With the handle gone, the remaining writes are no-ops and the record is
            | the evidence file, the console and the log.
            */
            $this->restore = null;
        }
    }

    /**
     * Re-insert a `backup_runs` row if the dump removed it, and return the id it now has.
     *
     * @param  array<string, mixed>|null  $attributes
     */
    private function reinstateBackupRun(?array $attributes): ?int
    {
        if ($attributes === null) {
            return null;
        }

        $uuid = (string) ($attributes['uuid'] ?? '');

        if ($uuid === '') {
            return null;
        }

        $existing = DB::table('backup_runs')->where('uuid', $uuid)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $row = $attributes;
        unset($row['id']);

        // `created_by` / `pruned_by` point at users who may not exist in the restored snapshot. The
        // archive row matters more than its bookkeeping, so the bookkeeping is what gives way.
        foreach (['created_by', 'updated_by', 'pruned_by'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null
                && ! DB::table('users')->where('id', $row[$column])->exists()) {
                $row[$column] = null;
            }
        }

        DB::table('backup_runs')->insert($row);

        return (int) DB::table('backup_runs')->where('uuid', $uuid)->value('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Endings
    |--------------------------------------------------------------------------
    */

    /**
     * A gate refused. **The target database was not written to**, and the site goes back up.
     *
     * Bringing the site up again is the part that is easy to forget: an abort that left the
     * application in maintenance mode would turn a refused gate into an outage, and the operator
     * would be debugging the wrong problem.
     *
     * **An abort that refused before step 6 has no row to advance, so it inserts one terminal.** A
     * refused production restore that left no trace at all would be exactly the attempt somebody
     * would want no trace of. The row carries what was true when the gate refused —
     * `checksum_verified` false when that was the gate, `pre_restore_backup_run_id` null when the
     * safety copy was never taken — and those columns are immutable, which is why this is an INSERT
     * rather than the update it looks as though it ought to be.
     */
    private function recordAbort(string $why, ?Throwable $exception = null): int
    {
        $stamps = [
            'finished_at' => Carbon::now(),
            'duration_seconds' => $this->elapsed(),
            'error_class' => $exception === null ? null : $exception::class,
            'error_message' => mb_substr($why, 0, 2000),
        ];

        if ($this->restore === null) {
            // Belt and braces. The only caller that can reach here with the dump already loaded is the
            // outer catch, and it routes to recordFailure() on `$databaseTouched` — but an `aborted`
            // row claiming the target is untouched, written over a target that is not, would send the
            // next operator away from the recovery they need.
            if ($this->databaseTouched) {
                $this->error('  ------- not recording this as aborted: the dump had already started going in.');
                $this->error('          The record is '.($this->evidenceFile ?? 'the log').' — treat this as a FAILED restore.');

                return self::EXIT_FAILED;
            }

            try {
                $this->insertRow(RestoreStatus::Aborted, $stamps);
            } catch (Throwable $insertFailure) {
                // The refusal still stands and the console still says so. A row that could not be
                // written must not turn a clean abort into a crash.
                Log::error('A refused restore could not be recorded.', [
                    'archive' => $this->archive->uuid,
                    'why' => mb_substr($why, 0, 500),
                    'exception' => $insertFailure::class,
                    'message' => $this->short($insertFailure),
                ]);

                $this->warn('  ------- the abort could not be recorded in backup_restores: '.$this->short($insertFailure));
            }
        } else {
            $this->transition(RestoreStatus::Aborted, $stamps);
        }

        $this->leaveMaintenance('the restore was aborted and nothing was written');

        $this->newLine();
        $this->error('  ABORTED — '.$why);
        $this->line('  '.RestoreStatus::Aborted->description());

        if ($this->preBackup !== null) {
            // It was taken, it is kept, and retention will not prune it (§6.10.3 rule 3). Said out
            // loud because an abort after step 5 leaves an archive an operator did not ask for, and
            // an unexplained archive is an archive somebody deletes.
            $this->line(sprintf(
                '  The pre-restore archive #%d was already taken and is kept — retention never prunes a pre_restore copy.',
                $this->preBackup->id,
            ));
        }

        $this->logActivity(RestoreStatus::Aborted, $why);

        return self::EXIT_ABORTED;
    }

    /**
     * It ran, and a proof did not hold. **The application stays down** (§6.10.5).
     *
     * Deliberately not followed by `php artisan up`: the restore's own instruction for this case is
     * that the site stays down and the next step is the pre-restore copy. A command that helpfully
     * lifted maintenance mode here would put a half-proved database in front of five panels.
     */
    private function recordFailure(string $why, ?Throwable $exception = null): int
    {
        $this->transition(RestoreStatus::Failed, [
            'finished_at' => Carbon::now(),
            'duration_seconds' => $this->elapsed(),
            'error_class' => $exception === null ? null : $exception::class,
            'error_message' => mb_substr($why, 0, 2000),
        ]);

        $this->newLine();
        $this->error('  FAILED — '.$why);
        $this->line('  '.RestoreStatus::Failed->description());
        $this->newLine();
        $this->error('  THE APPLICATION IS STILL DOWN. That is deliberate (§6.10.5).');
        $this->newLine();
        $this->line('  The way back is the archive taken two minutes ago:');

        if ($this->preBackup !== null) {
            $this->line(sprintf(
                '    php artisan backup:restore --backup=%d --target=%s --reason="..." --confirm="%s"',
                $this->preBackup->id,
                $this->target->value,
                self::phrase($this->database),
            ));
        } else {
            $this->warn('    There is no pre-restore archive: this was a '.$this->target->value.' restore.');
        }

        $this->newLine();
        $this->line('  When the database is where you want it: php artisan up');
        $this->line('  Evidence: '.($this->evidenceFile ?? 'not written').' · row '.($this->restore?->uuid ?? 'none'));

        $this->logActivity(RestoreStatus::Failed, $why);

        return self::EXIT_FAILED;
    }

    /**
     * Steps 12 and 13 — the worker, maintenance mode, and the record.
     *
     * @param  array<string, mixed>  $proof
     */
    private function recordCompletion(array $proof): int
    {
        $proof['evidence_file'] = $this->evidenceFile;

        $this->transition(RestoreStatus::Completed, [
            'finished_at' => Carbon::now(),
            'duration_seconds' => $this->elapsed(),
            'proof' => $proof,
        ]);

        $this->newLine();
        $this->line('  step 12 restart the queue worker now:');
        $this->line('            Windows  nssm start MyOfficeQueue');
        $this->line('            Linux    supervisorctl start myoffice-queue:*');
        $this->line('          then finish the commission work that was queued inside the snapshot:');
        $this->line('            php artisan commissions:sweep');

        $this->leaveMaintenance('the restore completed');

        $this->logActivity(RestoreStatus::Completed, 'Restore completed.');

        $this->newLine();
        $this->info('  COMPLETED — '.($this->restore?->summary() ?? 'restored '.$this->database));
        $this->line('  Check it: php artisan ops:health --deep');
        $this->line('  Evidence: '.($this->evidenceFile ?? 'not written').' · row '.($this->restore?->uuid ?? 'none'));
        $this->newLine();

        return self::EXIT_OK;
    }

    private function leaveMaintenance(string $because): void
    {
        if (! $this->broughtDown) {
            return;
        }

        try {
            Artisan::call('up');
            $this->line('  ------- maintenance mode lifted: '.$because);
            $this->broughtDown = false;
        } catch (Throwable $exception) {
            $this->error('  ------- the site is STILL DOWN and "up" failed: '.$this->short($exception));
            $this->error('          run: php artisan up');
        }
    }

    /**
     * Step 13's activity row.
     *
     * `activity()` directly rather than {@see WritesAuditTrail}, because
     * that trait takes the causer from the session and a console restore has none — the causer here
     * is the actor the gates resolved, and attributing the most consequential row in the log to
     * "system" would waste the only chance to say who it was.
     */
    private function logActivity(RestoreStatus $status, string $outcome): void
    {
        if ($this->restore === null) {
            return;
        }

        try {
            $restore = $this->restore;
            $reason = $this->reason;

            activity()
                ->performedOn($restore)
                ->causedBy($this->actor)
                ->withProperties([
                    'status' => $status->value,
                    'target' => $this->target->value,
                    'database' => $this->database,
                    'archive' => ['id' => $this->archive->id, 'uuid' => (string) $this->archive->uuid],
                    'pre_restore_archive' => $this->preBackup?->id,
                    'old' => ['row_counts' => $restore->row_counts_before, 'ledger_rows' => $restore->ledger_rows_before],
                    'attributes' => ['row_counts' => $restore->row_counts_after, 'ledger_rows' => $restore->ledger_rows_after],
                    'ledger_delta' => $restore->ledgerDelta(),
                    'evidence_file' => $this->evidenceFile,
                    'outcome' => mb_substr($outcome, 0, 500),
                    'via' => 'console',
                ])
                ->tap(function (ActivityContract $activity) use ($reason): void {
                    $activity->setAttribute('module', 'backups');
                    $activity->setAttribute('reason', mb_substr($reason, 0, 500));
                })
                ->log('Database restore: '.$status->value);
        } catch (Throwable $exception) {
            // The row and the evidence file are the record; the activity log is the convenient copy.
            Log::warning('The restore could not be written to the activity log.', [
                'restore' => $this->restore->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Small change
    |--------------------------------------------------------------------------
    */

    /**
     * How long this invocation has been going, in seconds.
     *
     * Falls back to {@see $attemptStartedAt} for the same reason that property exists: an abort
     * before step 7b has no `started_at` on the row yet, and `duration_seconds` on a refused restore
     * is the figure that separates "refused instantly" from "waited four minutes for a pre-restore
     * backup that then failed" — which is the difference between a typo and a broken backup path.
     *
     * The `??` reads an uninitialised typed property safely (isset semantics), so a test that calls
     * a step method directly gets null rather than an Error.
     */
    private function elapsed(): ?int
    {
        $startedAt = $this->restore?->started_at ?? ($this->attemptStartedAt ?? null);

        return $startedAt === null ? null : (int) max(0, (int) $startedAt->diffInSeconds(Carbon::now(), true));
    }

    private function short(Throwable $exception): string
    {
        return mb_substr(trim($exception->getMessage()), 0, 400);
    }

    private function targetList(): string
    {
        return implode(', ', array_map(
            static fn (RestoreTarget $target): string => $target->value,
            RestoreTarget::cases(),
        ));
    }

    /**
     * The three newest archives that could actually be restored, printed with the refusal.
     *
     * An operator who has just been told "no backup matches that" needs the ids, not a second
     * command to run to find them.
     */
    private function suggestArchives(): void
    {
        $candidates = BackupRun::query()
            ->usableDatabaseArchives()
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        if ($candidates->isEmpty()) {
            $this->newLine();
            $this->warn('  There is no usable database archive on this install at all.');

            return;
        }

        $this->newLine();
        $this->line('  The newest usable database archives:');

        foreach ($candidates as $candidate) {
            $this->line(sprintf(
                '    #%-5d %s  %s  %s',
                $candidate->id,
                ($candidate->finished_at ?? $candidate->created_at)?->toDateTimeString() ?? '',
                str_pad($candidate->verification_status->value, 11),
                (string) $candidate->filename,
            ));
        }
    }
}
