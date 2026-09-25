<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Enums\BackupType;
use App\Enums\BackupVerificationStatus;
use App\Enums\RestoreStatus;
use App\Enums\RestoreTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\RestoreBackupRequest;
use App\Http\Requests\Admin\Ops\RunBackupRequest;
use App\Models\Ops\BackupRestore;
use App\Models\Ops\BackupRun;
use App\Models\User;
use App\Policies\Ops\BackupRunPolicy;
use App\Services\Ops\BackupPathResolver;
use App\Services\Ops\BackupRetentionService;
use App\Services\Ops\BackupService;
use App\Services\Ops\BackupVerificationService;
use App\Support\Ops\RetentionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The operator's backup screens: what restore points exist, whether they are proven, and the one
 * button that overwrites a database (phase-24-25 section 7.1, section 8.1 to section 8.3).
 *
 * **Every dangerous decision on these screens is asked of `BackupRunPolicy` directly, never through
 * `Gate`.** `Gate::before` allows a Super Admin everything before a policy is consulted
 * (`CLAUDE.md` section 4), and section 6.10.3's rules are not permissions — "never prune the newest
 * usable database archive" binds the Super Admin more than anybody, because they are the only person
 * who can reach the button. So `$this->policy()` is resolved and asked, the route middleware carries
 * the permission half (`can:backups.*`), and the two together are the whole answer. A future
 * `Gate::allows('delete', $run)` here would silently hand the last copy of the database to whoever
 * holds the top role.
 *
 * **Nothing on these screens deletes a `backup_runs` row.** `backups.delete` prunes an archive
 * **file** early: {@see destroy()} removes the bytes and stamps `file_pruned_at`, `pruned_by` and
 * `status = pruned`, and the record of what was taken, when, how big it was and whether it ever
 * verified stays for ever (section 6.10.3 rule 4, D19). The model's `deleting` hook and
 * `trg_br_no_delete` are what make that a guarantee rather than a habit.
 *
 * **The archive is streamed by {@see download()} and by nothing else.** It lives on the private
 * `backups` disk, outside the webroot; there is no storage URL, no symlink and no framework route to
 * it, and this controller re-runs the permission chain on every byte it serves (D21). The `signed`
 * middleware on the route adds a five-minute expiry so a link that leaks stops working — **the
 * signature is not the authorization**, it is the leak window. Every download is written to the
 * activity log, because "who took a copy of the whole database off this server" is a question that
 * gets asked after the fact.
 *
 * **`backups.view_logs` is a separate disclosure from `backups.view`** (section 4.2). The register
 * says when a backup ran and whether it verified; the log text names tables, paths, the database and
 * the dump's own error output. {@see show()} therefore passes the log to the view only when the policy
 * allows it, rather than passing it and hoping the Blade remembers a `@can` — data a view never
 * receives cannot leak through a copy button or a print stylesheet. The archive password is never
 * read, never passed and never rendered anywhere: the only fact about it that reaches a screen is
 * whether the archive is encrypted at all (section 8.2).
 *
 * **A manual backup runs in the request, on purpose.** `BackupService` is the recorder as well as the
 * worker: it writes the `pending`/`running` row before the dump starts, so a killed process leaves
 * evidence rather than silence. A queued run would leave the operator watching a register that does
 * not change while they wonder whether the worker is alive — which is one of the things this screen
 * exists to answer. The `backup-run` limiter (two an hour) and the service's own cache lock are what
 * keep that safe; section 10.5's `RunBackupJob` is the right home for it the day an archive outgrows a
 * request, and then {@see store()} dispatches instead of calling, and the row polling the register
 * already does starts earning its keep.
 *
 * **Restoring is recorded here and executed from a console.** {@see restoreStore()} runs all four
 * gates of section 6.10.5, writes the permanent `backup_restores` row, and hands the operator the
 * exact command to run — because steps 3 and 4 of that procedure are `php artisan down` and stopping
 * the queue worker, and a web request cannot take down the server that is serving it and then keep
 * reporting progress. The row is written first so that a restore begun and abandoned is still a
 * restore somebody asked for. See {@see restoreStore()} for the one line that changes when
 * `BackupRestoreService::execute()` lands.
 */
final class BackupController extends Controller
{
    /**
     * Columns a reader may sort by. Never raw input — this list reaches an `ORDER BY`.
     *
     * `row_count_total` is here because a shrinking database is the cheapest corruption alarm there is
     * (section 8.1): sorting on it puts a night that dumped half the rows next to the nights that did
     * not.
     */
    private const SORTABLE = [
        'started_at',
        'finished_at',
        'type',
        'status',
        'trigger',
        'size_bytes',
        'row_count_total',
        'verification_status',
    ];

    /** Page sizes the select offers, mirroring the other operations registers. */
    private const PER_PAGE_OPTIONS = [15, 25, 50, 100];

    /**
     * How long a download link stays valid (section 7.1).
     *
     * Five minutes is long enough to click and short enough that a link pasted into a chat, a ticket or
     * a browser history is dead before anybody else reads it.
     */
    private const DOWNLOAD_LINK_MINUTES = 5;

    /**
     * The tables the "what will be lost" figure of section 8.3 step 1 is computed over.
     *
     * A curated subset of section 6.10.4's proof list: the tables where a row created since the archive
     * was taken represents something a person did that money or a record depends on. The wizard counts
     * `created_at > backup.started_at` on each of them and says the number out loud, because "47
     * receipts and 12 commission entries will be gone" is a sentence somebody stops for and "data may
     * be lost" is not.
     *
     * Deliberately not the whole proof list: a count over twenty-six tables on a page load buys nothing
     * an operator reads, and `activity_log` would dominate the number with rows about the restore
     * itself.
     *
     * @var array<string, string>
     */
    private const LOSS_TABLES = [
        'student_fee_payments' => 'fee receipts',
        'project_payments' => 'project payments',
        'collaborator_commission_ledger_entries' => 'commission entries',
        'collaborator_payouts' => 'partner payouts',
        'invoices' => 'invoices',
        'expenses' => 'expenses',
        'incomes' => 'other income',
        'students' => 'students',
        'student_admissions' => 'admissions',
        'projects' => 'projects',
        'clients' => 'clients',
        'users' => 'user accounts',
    ];

    /**
     * The database's table list, read once per request (see {@see existingTables()}).
     *
     * @var list<string>|null
     */
    private ?array $existingTables = null;

    /*
    |--------------------------------------------------------------------------
    | The register
    |--------------------------------------------------------------------------
    */

    /**
     * What restore points exist, whether they are proven, and where they live (section 8.1).
     */
    public function index(Request $request): View
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        abort_unless($policy->viewAny($user), 403);

        $filters = $this->validateFilters($request);
        $sort = $this->sortColumn($filters['sort'] ?? null);
        $direction = $this->sortDirection($filters['direction'] ?? null);

        $base = $this->filtered($request, $filters);

        $runs = (clone $base)
            ->with('creator:id,name')
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($filters))
            ->withQueryString();

        /*
        | Per-row answers, computed once in PHP rather than inside the Blade. Two reasons: the policy
        | memoises one query for the whole page (see `BackupRunPolicy`), and a signed URL built in a
        | view is a signed URL nobody can find later when its expiry needs changing.
        */
        $abilities = [];
        $downloadUrls = [];

        foreach ($runs as $run) {
            $abilities[$run->getKey()] = [
                'download' => $policy->download($user, $run),
                'verify' => $policy->verify($user, $run),
                'prune' => $policy->delete($user, $run),
                'restore' => $policy->restore($user, $run),
                // Why a button is off, in the operator's words. A disabled control with no explanation
                // is read as a bug (section 8.1 "Danger affordances").
                'prune_blocked_reason' => $this->pruneBlockedReason($run, $policy, $user),
                'restore_blocked_reason' => $this->restoreBlockedReason($run, $policy, $user),
            ];

            if ($abilities[$run->getKey()]['download']) {
                $downloadUrls[$run->getKey()] = $this->downloadUrl($run);
            }
        }

        return view('admin.backups.index', [
            'runs' => $runs,
            'abilities' => $abilities,
            'downloadUrls' => $downloadUrls,
            'summary' => $this->summary(),
            'sort' => $sort,
            'direction' => $direction,
            'typeOptions' => BackupType::options(),
            'statusOptions' => BackupStatus::options(),
            'triggerOptions' => BackupTrigger::options(),
            'verificationOptions' => BackupVerificationStatus::options(),
            'perPageOptions' => $this->perPageOptionsForSelect(),
            'hasFilters' => $this->hasActiveFilters($filters),
            'canCreate' => $policy->create($user),
            'canRestoreAny' => $policy->restoreAny($user),
            'typeChoices' => $this->typeChoices(),
            // True while any row on this page is still being written: the page polls itself, and only
            // then (section 8.1 "Behaviour").
            'isRunning' => $runs->contains(static fn (BackupRun $run): bool => $run->status->isInFlight()),
            'settingsUrl' => $this->settingsUrl($user),
        ]);
    }

    /**
     * Take a backup now.
     *
     * Synchronous — see the class note for why, and for the one line that changes when the queued job
     * of section 10.5 lands. Every failure mode here ends in a toast and a register that tells the
     * truth: the service has already written the `failed` row by the time the exception arrives.
     */
    public function store(RunBackupRequest $request, BackupService $backups): RedirectResponse
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        abort_unless($policy->create($user), 403);

        $type = $request->backupType();
        $reason = $request->reason();

        try {
            $run = $backups->run($type, BackupTrigger::Manual, $reason, $user);
        } catch (Throwable $exception) {
            report($exception);

            /*
            | Two different failures land here and the operator needs to be able to tell them apart:
            | "one is already running" (the cache lock refused, nothing is wrong) and "the dump did not
            | work" (a row exists, `failed`, with the error on it). The message is the service's own,
            | because it is the one that knows which happened.
            */
            session()->flash('toast', [
                'type' => 'error',
                'title' => 'The backup did not complete',
                'message' => Str::limit($exception->getMessage(), 240),
            ]);

            return redirect()->route('admin.backups.index');
        }

        $this->record($user, $run, 'backup.run.manual', [
            'type' => $type->value,
            'trigger' => BackupTrigger::Manual->value,
            'status' => $run->status->value,
            'size_bytes' => $run->size_bytes,
            'filename' => $run->filename,
        ], $reason);

        session()->flash('toast', [
            'type' => $run->status === BackupStatus::Completed ? 'success' : 'warning',
            'title' => $run->status === BackupStatus::Completed ? 'Backup taken' : 'Backup finished '.$run->status->label(),
            'message' => $run->summary(),
        ]);

        return redirect()->route('admin.backups.show', $run);
    }

    /**
     * One restore point, and the evidence for trusting it (section 8.2).
     */
    public function show(Request $request, BackupRun $backup): View
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        abort_unless($policy->view($user, $backup), 403);

        $canReadLogs = $policy->viewLogs($user, $backup);
        $canDownload = $policy->download($user, $backup);

        return view('admin.backups.show', [
            'run' => $backup->loadMissing('creator:id,name'),
            'restores' => $this->restoresOf($backup),
            'fileOnDisk' => $this->fileExists($backup),
            // The tables the recorded counts cover. Numbers per table are deliberately absent — see
            // `proofTables()`.
            'proofTables' => $this->proofTables(),
            // Null, not an empty string: the view says one thing for "there is no log output" and
            // another for "not yours to read", and it cannot tell them apart from "".
            'log' => $canReadLogs ? $this->logText($backup) : null,
            'canReadLogs' => $canReadLogs,
            'canDownload' => $canDownload,
            'downloadUrl' => $canDownload ? $this->downloadUrl($backup) : null,
            'canVerify' => $policy->verify($user, $backup),
            'canPrune' => $policy->delete($user, $backup),
            'pruneBlockedReason' => $this->pruneBlockedReason($backup, $policy, $user),
            'canRestore' => $policy->restore($user, $backup),
            'restoreBlockedReason' => $this->restoreBlockedReason($backup, $policy, $user),
        ]);
    }

    /**
     * Stream the archive.
     *
     * The permission chain runs again here — route middleware, then the policy — because this is the
     * only door to the private disk and a signed URL proves only that this application minted the link
     * (D21, and the class note). The file is streamed rather than read into memory: a full archive is
     * measured in gigabytes.
     */
    public function download(Request $request, BackupRun $backup): StreamedResponse
    {
        $user = $this->actor($request);

        abort_unless($this->policy()->download($user, $backup), 403);
        abort_if($backup->path === null, 404);
        abort_unless($this->fileExists($backup), 404);

        $this->record($user, $backup, 'backup.download', [
            'filename' => $backup->filename,
            'disk' => $backup->disk,
            'size_bytes' => $backup->size_bytes,
            'is_encrypted' => $backup->is_encrypted,
            'includes_env' => $backup->includes_env,
        ]);

        return Storage::disk($backup->disk)->download(
            $backup->path,
            $backup->filename ?? basename($backup->path),
            [
                // A copy of the database is never anybody's cache entry, and never an intermediary's.
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Verify an archive now: the bytes match the recorded checksum and the zip opens.
     *
     * Level one of section 6.10.4. The deep proof — restore it into the scratch database and prove the
     * money on the restored copy — is `backup:verify --deep` and the weekly scheduled run, because it
     * creates and drops a database and that is not a thing a web request should do while somebody
     * watches a spinner.
     */
    public function verify(Request $request, BackupRun $backup, BackupVerificationService $verification): RedirectResponse
    {
        $user = $this->actor($request);

        abort_unless($this->policy()->verify($user, $backup), 403);

        try {
            $verified = $verification->verifyChecksum($backup);
        } catch (Throwable $exception) {
            report($exception);

            session()->flash('toast', [
                'type' => 'error',
                'title' => 'The verification could not run',
                'message' => 'Nothing was recorded against this backup. The application log has the detail.',
            ]);

            return redirect()->route('admin.backups.show', $backup);
        }

        $this->record($user, $verified, 'backup.verify', [
            'verification_status' => $verified->verification_status->value,
            'verified_at' => $verified->verified_at?->toIso8601String(),
        ]);

        session()->flash('toast', [
            'type' => match ($verified->verification_status) {
                BackupVerificationStatus::Failed => 'error',
                BackupVerificationStatus::Unverified => 'warning',
                default => 'success',
            },
            'title' => $verified->verification_status->label(),
            'message' => $verified->verification_status->description(),
        ]);

        return redirect()->route('admin.backups.show', $verified);
    }

    /**
     * Prune this archive's **file**, ahead of its retention date.
     *
     * The row survives, and so does everything on it (section 6.10.3 rule 4, D19). The policy has
     * already refused the newest usable database archive, a `pre_restore` / `pre_deploy` copy, and any
     * prune that would drop below `backup.retention_min_copies` — those refusals are not re-stated
     * here, they are asked again by {@see BackupRunPolicy::delete()} on this request, because a
     * register rendered two minutes ago is not evidence about now.
     */
    public function destroy(Request $request, BackupRun $backup, BackupPathResolver $paths): RedirectResponse
    {
        $user = $this->actor($request);

        abort_unless($this->policy()->delete($user, $backup), 403);

        $wasSize = $backup->size_bytes;

        try {
            $deleted = $paths->deleteFile($backup);

            // The file is still there. The row is left alone deliberately: stamping `file_pruned_at`
            // while the bytes remain would make every storage figure in the system a fiction, and the
            // next ceiling check is computed from those figures.
            if (! $deleted && $paths->exists($backup)) {
                session()->flash('toast', [
                    'type' => 'error',
                    'title' => 'The archive is still on disk',
                    'message' => 'The file would not delete, so the record was left untouched. Check the '
                        .'disk permissions on the backups disk.',
                ]);

                return redirect()->route('admin.backups.show', $backup);
            }

            /*
            | MAIN SESSION: this stamp belongs in `BackupRetentionService::pruneOne()`. The service owns
            | `markPruned()` and it is private, and this slice may not edit that file — so the six lines
            | below mirror it exactly, including "a failed run keeps its `failed` status": overwriting
            | `failed` here would erase the evidence that a backup did not work on a night somebody may
            | later need to ask about, and the file being gone is already recorded by `file_pruned_at`.
            */
            DB::transaction(static function () use ($backup, $user): void {
                $attributes = [
                    'file_pruned_at' => Carbon::now(),
                    'pruned_by' => $user->getKey(),
                ];

                if ($backup->status === BackupStatus::Completed) {
                    $attributes['status'] = BackupStatus::Pruned;
                }

                $backup->forceFill($attributes)->save();
            });
        } catch (Throwable $exception) {
            report($exception);

            session()->flash('toast', [
                'type' => 'error',
                'title' => 'The archive was not pruned',
                'message' => Str::limit($exception->getMessage(), 240),
            ]);

            return redirect()->route('admin.backups.show', $backup);
        }

        $this->record($user, $backup, 'backup.file_pruned', [
            'filename' => $backup->filename,
            'disk' => $backup->disk,
            'size_bytes' => $wasSize,
            'status' => $backup->status->value,
            'pruned_early' => $backup->retention_until !== null
                && Carbon::instance($backup->retention_until)->startOfDay()->greaterThan(Carbon::today()),
        ]);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Archive file removed',
            'message' => 'The record of this backup is kept for ever — only the file is gone'
                .($wasSize === null ? '.' : ', freeing '.RetentionPlan::humanBytes((string) $wasSize).'.'),
        ]);

        return redirect()->route('admin.backups.show', $backup);
    }

    /*
    |--------------------------------------------------------------------------
    | Restore (section 8.3)
    |--------------------------------------------------------------------------
    */

    /**
     * The restore wizard: what will be overwritten, why, and the phrase that has to be typed.
     *
     * `password.confirm` has already run on this route, so reaching this method means the operator
     * re-authenticated within the confirmation window. The step-1 figures are computed here and
     * nowhere else: "what will be lost" is a comparison between the live database and the age of an
     * archive, and a Blade that computed it would compute it again on every validation failure.
     */
    public function restoreCreate(Request $request, BackupRun $backup): View
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        abort_unless($policy->restore($user, $backup), 403);

        $liveDatabase = $this->liveDatabaseName();

        return view('admin.backups.restore', [
            'run' => $backup->loadMissing('creator:id,name'),
            'liveDatabase' => $liveDatabase,
            'scratchDatabase' => $this->scratchDatabaseName($liveDatabase),
            'targetOptions' => $this->targetOptions(),
            'defaultTarget' => $this->defaultTarget()->value,
            'isProduction' => app()->environment('production'),
            // Step 1: the rows recorded since this archive was taken, by table, with a plain-language
            // label. Empty when the figures could not be read — never a zero, which would read as
            // "nothing will be lost" (see `rowsCreatedSince()`).
            'losses' => $this->rowsCreatedSince($backup),
            'lossesMeasured' => $this->lossesMeasurable($backup),
            'liveCounts' => $this->liveProofCounts(),
            // The phrase template with `{date}` already resolved and `{database}` still in place; the
            // browser substitutes the database name as it is typed, the wizard's no-JavaScript fallback
            // substitutes the pre-filled one, and the server compares the whole string again. One
            // template, three renderings of it, and no second implementation of the phrase anywhere.
            'phraseTemplate' => RestoreBackupRequest::phraseTemplate($backup),
            'previousRestores' => $this->restoresOf($backup),
        ]);
    }

    /**
     * Record the restore request, with all four gates stamped on it.
     *
     * **The row is written before anything is executed, and `checksum_verified` stays false until the
     * bytes are re-hashed.** Section 2.2 stores the gates as stamps rather than re-deriving them later,
     * and a stamp that says the archive was checked has to mean it was checked *now* — not that a row
     * remembered a verification from Tuesday. The policy has already refused an archive whose recorded
     * checksum was never proved; the executor proves it again as step 2 and stamps this column then.
     *
     * MAIN SESSION: when `BackupRestoreService::execute()` and `php artisan backup:restore` land
     * (section 6.10.5), the handoff below becomes `$restores->execute($restore)` — a queued or console
     * execution, never an inline one. It cannot be inline today and should not be later: steps 3 and 4
     * of the procedure are `php artisan down` and stopping the queue worker, and a request cannot take
     * down the server that is serving it and then report progress from the other side.
     */
    public function restoreStore(RestoreBackupRequest $request, BackupRun $backup): RedirectResponse
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        abort_unless($policy->restore($user, $backup), 403);

        $target = $request->target();
        $database = $request->databaseName();
        $reason = $request->reason();

        $restore = DB::transaction(function () use ($backup, $target, $database, $reason, $request, $user): BackupRestore {
            // `forceFill`, not mass assignment: every value here is either validated by the Form
            // Request or written by this method, and none of it may arrive from the form directly —
            // `requested_by` above all, which is the column that answers "who did this" and is a
            // RESTRICT foreign key precisely so the answer outlives the account.
            $restore = (new BackupRestore)->forceFill([
                'uuid' => (string) Str::ulid(),
                'backup_run_id' => $backup->getKey(),
                'pre_restore_backup_run_id' => null,
                'target' => $target,
                'status' => RestoreStatus::Requested,
                'database_name' => $database,
                'reason' => $reason,
                'requested_by' => $user->getKey(),
                'confirmed_at' => $request->confirmedAt(),
                'password_confirmed_at' => $this->passwordConfirmedAt(),
                // See the method note. False until the executor re-hashes the archive.
                'checksum_verified' => false,
            ]);

            $restore->save();

            return $restore;
        });

        $this->record($user, $restore, 'backup.restore.requested', [
            'backup_run' => $backup->uuid,
            'target' => $target->value,
            'database_name' => $database,
            'archive_taken_at' => $backup->started_at?->toIso8601String(),
            'archive_verification' => $backup->verification_status->value,
            'confirmation_phrase_typed' => $request->confirmedAt() !== null,
        ], $reason);

        /*
        | **The invocation printed here must be one the console accepts.** It used to say
        | `--restore=<uuid>`, which `backup:restore` has never had — Symfony rejects an unknown
        | option before `handle()` runs, so the operator's first action after passing four
        | deliberately slow gates was a command that errored out, at the point in an incident where
        | they are least able to debug a flag.
        |
        | The reason and the confirmation phrase are re-supplied on the console deliberately: the
        | command re-derives the phrase from `backup.restore_confirmation_phrase` and compares it
        | with `hash_equals()`, so the gate is enforced where the restore actually happens rather
        | than trusted from a row written by a browser.
        |
        | T51: the command opens its own `backup_restores` row rather than adopting this one, so
        | the `confirmed_at` and `password_confirmed_at` stamps written above stay at `requested`,
        | detached from the restore that ran. That needs a `--restore=<uuid>` option on the command
        | itself.
        */
        session()->flash('toast', [
            'type' => 'warning',
            'title' => 'Restore recorded — it has not run yet',
            'message' => sprintf(
                'Restore %s is logged against %s. Run it from the server console: '
                .'php artisan backup:restore --backup=%s --target=%s --database=%s '
                .'--reason="…" --confirm="…". The reason and the confirmation phrase are asked for '
                .'again there, because the console is where the phrase is checked. The procedure '
                .'takes the site down, stops the worker and takes a safety copy first, which is why '
                .'it is not started from a browser.',
                Str::limit($restore->uuid, 10, ''),
                $database,
                $restore->backupRun?->uuid ?? '<backup uuid>',
                $restore->target instanceof \App\Enums\RestoreTarget
                    ? $restore->target->value
                    : (string) $restore->target,
                $database,
            ),
        ]);

        return redirect()->route('admin.backups.show', $backup);
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<BackupRun>
     */
    private function filtered(Request $request, array $filters): Builder
    {
        $query = BackupRun::query();

        if (($type = $this->stringFilter($filters, 'type')) !== null) {
            $query->where('type', $type);
        }

        if (($status = $this->stringFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        if (($trigger = $this->stringFilter($filters, 'trigger')) !== null) {
            $query->where('trigger', $trigger);
        }

        if (($verification = $this->stringFilter($filters, 'verification_status')) !== null) {
            $query->where('verification_status', $verification);
        }

        if (($offsite = $this->stringFilter($filters, 'offsite')) !== null) {
            $offsite === '1'
                ? $query->whereNotNull('offsite_copied_at')
                : $query->whereNull('offsite_copied_at');
        }

        if (($onDisk = $this->stringFilter($filters, 'on_disk')) !== null) {
            // "Still on disk" is `file_pruned_at IS NULL` **and** a path: a failed run that never wrote
            // anything has neither a file nor a prune stamp, and counting it as present would put a
            // download button next to nothing.
            $onDisk === '1'
                ? $query->whereNull('file_pruned_at')->whereNotNull('path')
                : $query->where(static function (Builder $inner): void {
                    $inner->whereNotNull('file_pruned_at')->orWhereNull('path');
                });
        }

        if (($from = $this->boundary($filters, 'from', false, $request)) !== null) {
            $query->where('started_at', '>=', $from);
        }

        if (($to = $this->boundary($filters, 'to', true, $request)) !== null) {
            $query->where('started_at', '<=', $to);
        }

        if (($term = $this->stringFilter($filters, 'search')) !== null) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(static function (Builder $inner) use ($escaped): void {
                $inner
                    ->where('uuid', 'like', $escaped)
                    ->orWhere('filename', 'like', $escaped)
                    ->orWhere('reason', 'like', $escaped)
                    ->orWhere('database_name', 'like', $escaped);
            });
        }

        return $query;
    }

    /**
     * The four figures of section 8.1, each one a question an operator opens this screen with.
     *
     * Sizes come from the recorded `size_bytes` rather than from a walk of the disk. A directory scan
     * on every page load would be the slowest thing on the screen, and the recorded figure is what the
     * retention ceiling is judged against anyway — a file whose size on disk disagrees with its row is
     * a finding for `backup:verify`, not a number to render here.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $latestDatabase = null;
        $latestProven = null;
        $usableDatabaseCount = 0;
        $storedBytes = '0';
        $offsiteCount = 0;
        $onDiskCount = 0;

        try {
            $latestDatabase = BackupRun::query()
                ->usableDatabaseArchives()
                ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
                ->orderByDesc('id')
                ->first();

            $latestProven = BackupRun::query()
                ->usable()
                ->where('verification_status', BackupVerificationStatus::RestoreOk->value)
                ->orderByDesc('verified_at')
                ->orderByDesc('id')
                ->first();

            $usableDatabaseCount = BackupRun::query()->usableDatabaseArchives()->count();

            $onDisk = BackupRun::query()->whereNull('file_pruned_at')->whereNotNull('path');
            $onDiskCount = (clone $onDisk)->count();
            $storedBytes = (string) ((clone $onDisk)->sum('size_bytes') ?: 0);

            $offsiteCount = (clone $onDisk)->whereNotNull('offsite_copied_at')->count();
        } catch (Throwable) {
            // A summary that cannot be computed shows dashes; the rows below it are what somebody came
            // for, and a register that 500s because a stat card failed is a register nobody can read.
        }

        $ceiling = $this->storageCeilingBytes();

        return [
            'latest_database' => $latestDatabase,
            'latest_proven' => $latestProven,
            'usable_database_archives' => $usableDatabaseCount,
            'minimum_copies' => $this->minimumCopies(),
            'on_disk_count' => $onDiskCount,
            'stored_bytes' => $storedBytes,
            'stored_human' => RetentionPlan::humanBytes($storedBytes),
            'ceiling_bytes' => $ceiling,
            'ceiling_human' => RetentionPlan::humanBytes($ceiling),
            'ceiling_breached' => bccomp($storedBytes, $ceiling, 0) > 0,
            'offsite_count' => $offsiteCount,
            'offsite_disk' => $this->offsiteDiskName(),
            'offsite_required' => (bool) setting('backup.offsite_required_for_go_live', true),
        ];
    }

    /**
     * The restores that used this archive, and the ones that used it as their safety copy.
     *
     * Both edges, because both are facts about this file: one says "this archive was installed", the
     * other says "this archive is what somebody's way back was".
     *
     * @return \Illuminate\Support\Collection<int, BackupRestore>
     */
    private function restoresOf(BackupRun $run)
    {
        try {
            return BackupRestore::query()
                ->with('requester:id,name')
                ->where(static function (Builder $query) use ($run): void {
                    $query
                        ->where('backup_run_id', $run->getKey())
                        ->orWhere('pre_restore_backup_run_id', $run->getKey());
                })
                ->orderByDesc('id')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Restore figures
    |--------------------------------------------------------------------------
    */

    /**
     * Rows recorded since this archive was taken, per table — the data a restore would discard.
     *
     * Counted from `created_at > backup.started_at` (section 8.3 step 1). Only tables that exist and
     * carry `created_at` are counted, and a table that cannot be counted is **omitted rather than
     * reported as zero**: "nothing was added here" and "this could not be measured" must never render
     * as the same sentence on the one screen where the number is the warning.
     *
     * @return list<array{table: string, label: string, count: int}>
     */
    private function rowsCreatedSince(BackupRun $run): array
    {
        $since = $run->started_at ?? $run->created_at;

        if ($since === null) {
            return [];
        }

        $existing = $this->existingTables();
        $rows = [];

        foreach (self::LOSS_TABLES as $table => $label) {
            if (! in_array($table, $existing, true)) {
                continue;
            }

            try {
                /*
                | One COUNT per table and no `Schema::hasColumn()` beside it. `hasColumn()` would run a
                | second information_schema query per table with the same SQL and different bindings —
                | twelve of them, which is the shape the N+1 guard of section 11.7 fails a screen for
                | (no single SQL string more than three times). A table without `created_at` throws
                | here instead, and every business table has one (`CLAUDE.md` section 3).
                */
                $count = (int) DB::table($table)->where('created_at', '>', $since)->count();
            } catch (Throwable) {
                continue;
            }

            if ($count > 0) {
                $rows[] = ['table' => $table, 'label' => $label, 'count' => $count];
            }
        }

        // Biggest first: the number somebody needs to see is the one they will regret.
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * Could the loss figure be computed at all?
     *
     * The wizard renders "nothing has been recorded since" only when this is true. When it is false the
     * wizard says the figure could not be measured, which is a different and more alarming sentence.
     */
    private function lossesMeasurable(BackupRun $run): bool
    {
        if (($run->started_at ?? $run->created_at) === null) {
            return false;
        }

        return in_array(array_key_first(self::LOSS_TABLES), $this->existingTables(), true);
    }

    /**
     * The tables this database actually has, read once per request.
     *
     * Memoised because {@see rowsCreatedSince()} and {@see lossesMeasurable()} both ask, and because
     * one listing beats a `Schema::hasTable()` per table — see the note inside `rowsCreatedSince()`.
     *
     * @return list<string>
     */
    private function existingTables(): array
    {
        if ($this->existingTables !== null) {
            return $this->existingTables;
        }

        try {
            $this->existingTables = array_values(array_map(
                static fn (array $table): string => (string) ($table['name'] ?? ''),
                Schema::getTables(),
            ));
        } catch (Throwable) {
            // Unreadable schema. An empty list means the wizard reports "could not be measured",
            // which is the honest answer and deliberately not "nothing will be lost".
            return [];
        }

        return $this->existingTables;
    }

    /**
     * The live row counts over section 6.10.4's proof list — the "before" side of step 1.
     *
     * Read through `BackupVerificationService::proofFigures()` so the wizard, the archive's own recorded
     * figures and both sides of a restore all count the same tables the same way. A `-1` from that
     * service means "this table is not there", which is a finding rather than a count, and the view
     * renders it as such.
     *
     * @return array<string, int>
     */
    private function liveProofCounts(): array
    {
        try {
            $database = $this->liveDatabaseName();

            if ($database === null) {
                return [];
            }

            return app(BackupVerificationService::class)->proofFigures($database);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * When the `password.confirm` middleware last accepted a password, as the stamp section 2.2 wants.
     *
     * Read from the session key Laravel's own middleware writes, so the stamp records the moment the
     * operator actually re-authenticated rather than the moment they pressed submit. Falls back to now
     * only because the column is the evidence that the gate was passed and the middleware guarantees it
     * was — a null here would read as "the gate was skipped".
     */
    private function passwordConfirmedAt(): Carbon
    {
        $stamp = session('auth.password_confirmed_at');

        if (is_numeric($stamp)) {
            return Carbon::createFromTimestamp((int) $stamp);
        }

        return Carbon::now();
    }

    /*
    |--------------------------------------------------------------------------
    | Why a button is off
    |--------------------------------------------------------------------------
    */

    /**
     * The operator's explanation for a disabled Prune control (section 8.1 "Danger affordances").
     *
     * Null when the control is available. The order matches {@see BackupRunPolicy::delete()}, so the
     * sentence on screen is the rule that actually refused — a tooltip that guesses is worse than none.
     */
    private function pruneBlockedReason(BackupRun $run, BackupRunPolicy $policy, User $user): ?string
    {
        if ($policy->delete($user, $run)) {
            return null;
        }

        if (! $user->can('backups.delete')) {
            return 'Pruning an archive needs the backups.delete permission.';
        }

        if ($run->path === null) {
            return 'This run never wrote an archive, so there is no file to remove.';
        }

        if ($run->file_pruned_at !== null) {
            return 'The file is already gone. The record of the backup stays for ever.';
        }

        if ($run->isProtectedFromPruning()) {
            return $run->trigger->label().' copies are never pruned: this file is the way back from the '
                .'thing it was taken for.';
        }

        return sprintf(
            'This is one of the database copies the retention floor protects — the newest one is never '
            .'prunable, and at least %d usable database archive(s) must remain.',
            $this->minimumCopies(),
        );
    }

    /**
     * The same, for Restore.
     */
    private function restoreBlockedReason(BackupRun $run, BackupRunPolicy $policy, User $user): ?string
    {
        if ($policy->restore($user, $run)) {
            return null;
        }

        if (! $policy->restoreAny($user)) {
            return 'Restoring is Super Admin\'s alone (backups.restore), and it additionally requires a '
                .'password confirmation, a typed phrase and a written reason.';
        }

        if (! $run->type->includesDatabase()) {
            return 'A files-only archive holds no database. Restore the database archive of the same '
                .'night, then the files beside it.';
        }

        if (! $run->isUsable()) {
            return 'There is no archive on disk to restore from. The record is kept, the file is not.';
        }

        return 'This archive has never been verified. Verify it first — restoring from a file whose '
            .'checksum nobody has checked is how a corrupt archive becomes a corrupt database.';
    }

    /*
    |--------------------------------------------------------------------------
    | Filter validation — inline only because this slice may not add a Form Request
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md section 9 puts validation in a Form Request; these rules are inline because the slice's
    | file list holds two Form Requests and neither of them is a filter bag. They are self-contained, so
    | lifting them into `BackupFilterRequest` is a move rather than a rewrite.
    */

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            'type' => ['nullable', 'string', Rule::in(BackupType::values())],
            'status' => ['nullable', 'string', Rule::in(BackupStatus::values())],
            'trigger' => ['nullable', 'string', Rule::in(BackupTrigger::values())],
            'verification_status' => ['nullable', 'string', Rule::in(BackupVerificationStatus::values())],
            'offsite' => ['nullable', 'string', Rule::in(['0', '1'])],
            'on_disk' => ['nullable', 'string', Rule::in(['0', '1'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in($this->perPageOptions())],
        ], [], [
            'verification_status' => 'verification',
            'on_disk' => 'file on disk',
            'from' => 'start date',
            'to' => 'end date',
            'per_page' => 'page size',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A date typed in the reader's timezone, snapped to the whole day, in the storage timezone (D61:
     * stored timestamps are UTC, the reader types local dates).
     *
     * @param  array<string, mixed>  $filters
     */
    private function boundary(array $filters, string $key, bool $endOfDay, Request $request): ?CarbonImmutable
    {
        $value = $this->stringFilter($filters, $key);

        if ($value === null) {
            return null;
        }

        try {
            $timezone = $this->timezone($this->actor($request));
            $date = CarbonImmutable::parse($value, $timezone);

            return ($endOfDay ? $date->endOfDay() : $date->startOfDay())
                ->setTimezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        foreach (['search', 'type', 'status', 'trigger', 'verification_status', 'offsite', 'on_disk', 'from', 'to'] as $key) {
            if ($this->stringFilter($filters, $key) !== null) {
                return true;
            }
        }

        return false;
    }

    private function sortColumn(mixed $sort): string
    {
        return is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : 'started_at';
    }

    private function sortDirection(mixed $direction): string
    {
        return is_string($direction) && strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $value = $filters['per_page'] ?? null;

        if ($value === null) {
            return per_page();
        }

        return in_array((int) $value, $this->perPageOptions(), true) ? (int) $value : per_page();
    }

    /**
     * The sizes the rules accept and the select offers: the fixed set plus whatever
     * `appearance.table_page_size` is, or the select could not show the size the page rendered at.
     *
     * @return list<int>
     */
    private function perPageOptions(): array
    {
        $options = self::PER_PAGE_OPTIONS;
        $options[] = per_page();
        $options = array_values(array_unique($options));
        sort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function perPageOptionsForSelect(): array
    {
        $options = [];

        foreach ($this->perPageOptions() as $size) {
            $options[(string) $size] = $size.' / page';
        }

        return $options;
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation helpers — the view does no arithmetic and no lookups
    |--------------------------------------------------------------------------
    */

    /**
     * The types the "Back up now" modal offers, with the sentence each one means.
     *
     * @return array<string, string>
     */
    private function typeChoices(): array
    {
        $choices = [];

        foreach (BackupType::cases() as $case) {
            $choices[$case->value] = $case->label();
        }

        return $choices;
    }

    /**
     * The restore targets this environment may choose (section 8.3 step 2).
     *
     * Production is offered only in production, and the Form Request refuses it anywhere else: a
     * staging box that can post `target=production` writes a permanent row claiming production was
     * overwritten.
     *
     * @return array<string, string>
     */
    private function targetOptions(): array
    {
        $options = [];

        foreach (RestoreTarget::cases() as $case) {
            if ($case === RestoreTarget::Production && ! app()->environment('production')) {
                continue;
            }

            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Production is preselected only when this really is production; elsewhere the safest target is.
     */
    private function defaultTarget(): RestoreTarget
    {
        return app()->environment('production') ? RestoreTarget::Production : RestoreTarget::Local;
    }

    /**
     * The tables section 6.10.4's recorded counts cover.
     *
     * The **list**, not per-table numbers: `backup_runs` records `table_count` and `row_count_total`
     * and no per-table breakdown (section 2.1), and filling that gap with counts from the live database
     * would put today's figures under an archive's heading. The per-table numbers exist exactly where
     * they mean something — `backup_restores.row_counts_before` / `row_counts_after`, captured either
     * side of a restore.
     *
     * @return list<string>
     */
    private function proofTables(): array
    {
        return BackupVerificationService::PROOF_TABLES;
    }

    /**
     * Everything this run recorded about what went wrong or what was proved — the `view_logs`
     * disclosure.
     *
     * Assembled from the row rather than from a log file: the dump's own output is scrubbed by the
     * service before it reaches `error_message` (section 2.1), and that scrubbed text is the only
     * version anybody should ever read — an unscrubbed dump log can carry the database password.
     */
    private function logText(BackupRun $run): ?string
    {
        $lines = [];

        if ($run->error_class !== null) {
            $lines[] = 'Exception: '.$run->error_class;
        }

        if ($run->error_message !== null && trim($run->error_message) !== '') {
            $lines[] = trim($run->error_message);
        }

        if ($run->verification_notes !== null && trim($run->verification_notes) !== '') {
            $lines[] = 'Verification: '.trim($run->verification_notes);
        }

        if ($run->notes !== null && trim($run->notes) !== '') {
            $lines[] = 'Notes: '.trim($run->notes);
        }

        if ($run->path !== null) {
            $lines[] = 'Archive: '.$run->disk.':'.$run->path;
        }

        return $lines === [] ? null : implode("\n\n", $lines);
    }

    /**
     * A five-minute signed link to {@see download()}.
     *
     * The expiry is the point: the URL is the thing that gets pasted into a ticket. Authorization is
     * still re-run on the request it opens — see the class note and D21.
     */
    private function downloadUrl(BackupRun $run): string
    {
        return URL::temporarySignedRoute(
            'admin.backups.download',
            Carbon::now()->addMinutes(self::DOWNLOAD_LINK_MINUTES),
            ['backup' => $run->getKey()],
        );
    }

    /**
     * Is the archive actually there?
     *
     * Asked of the disk, not of the row: `status = completed` with no file is exactly the state a
     * half-finished copy, a manual deletion or a mounted drive that went away leaves behind, and it is
     * the state the download and restore buttons must not be rendered over.
     */
    private function fileExists(BackupRun $run): bool
    {
        if ($run->path === null) {
            return false;
        }

        try {
            return app(BackupPathResolver::class)->exists($run);
        } catch (Throwable) {
            return false;
        }
    }

    private function liveDatabaseName(): ?string
    {
        try {
            $name = app(BackupPathResolver::class)->databaseName();
        } catch (Throwable) {
            return null;
        }

        return trim($name) === '' ? null : $name;
    }

    /**
     * `backup.restore_scratch_database`, never the live database.
     *
     * The setting is validated to refuse the live name, and this is the second line: a scratch name
     * that collided with production would be pre-filled into the wizard's own form field.
     */
    private function scratchDatabaseName(?string $live): string
    {
        $configured = setting('backup.restore_scratch_database', 'my_office_restore_test');
        $name = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'my_office_restore_test';

        if ($live !== null && strcasecmp($name, $live) === 0) {
            return $live.'_restore_test';
        }

        return $name;
    }

    private function offsiteDiskName(): ?string
    {
        try {
            return app(BackupPathResolver::class)->offsiteDiskName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `backup.max_storage_gb` in bytes, through the service that owns the conversion.
     *
     * bcmath, never a float: `20.00 * 1024 ** 3` is the multiplication a float turns into
     * 21474836479.999996.
     */
    private function storageCeilingBytes(): string
    {
        try {
            return app(BackupRetentionService::class)->storageCeilingBytes();
        } catch (Throwable) {
            return '0';
        }
    }

    private function minimumCopies(): int
    {
        $configured = setting('backup.retention_min_copies', 3);

        return is_numeric($configured) ? max(1, (int) $configured) : 3;
    }

    /**
     * The backup settings group, when the reader may read settings — the empty state links to it
     * (section 8.1).
     */
    private function settingsUrl(User $user): ?string
    {
        try {
            if (! $user->can('settings.view')) {
                return null;
            }

            return route('admin.settings.index', ['group' => 'backup']);
        } catch (Throwable) {
            // The settings screen belongs to another phase; a missing route must not take this page
            // down over a link in an empty state.
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The activity row for something a person did to a backup.
     *
     * A backup run is already a permanent record of itself; this is the record of a *person* — who took
     * it, who streamed a copy of the database off the server, who removed a file early and why. That is
     * a different fact and the one an auditor asks about.
     *
     * @param  array<string, mixed>  $properties
     */
    private function record(User $user, BackupRun|BackupRestore $subject, string $event, array $properties = [], ?string $reason = null): void
    {
        try {
            activity('backups')
                ->causedBy($user)
                ->performedOn($subject)
                ->withProperties($properties + ['uuid' => $subject->uuid, 'reason' => $reason])
                ->log($event);
        } catch (Throwable) {
            // The backup, the stream or the prune has already happened and the row records it; an
            // activity write that failed must not undo any of them.
        }
    }

    private function policy(): BackupRunPolicy
    {
        return app(BackupRunPolicy::class);
    }

    /**
     * The signed-in user, typed. The route stack guarantees one; this makes that a statement the type
     * system can hold rather than a comment.
     */
    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function timezone(User $user): string
    {
        try {
            return $user->effectiveTimezone();
        } catch (Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
