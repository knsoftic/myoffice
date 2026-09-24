<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\BackupStatus;
use App\Models\Ops\BackupRun;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Support\Ops\RetentionPlan;
use App\Support\Ops\RetentionResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The retention ladder, and the prune plan it produces (phase-24-25 §6.10.3).
 *
 * **This class removes files. It never removes a row.** A `backup_runs` row is append-only (D19,
 * §2.1) and is protected by a model hook and a `BEFORE DELETE` trigger below it; a prune sets
 * `file_pruned_at`, `pruned_by` and — for a run that had completed — `status = pruned`. The history
 * of what was taken, when, how big it was and whether it ever verified outlives the bytes, because
 * that history is what a restore argument is settled with.
 *
 * **`retention_min_copies` is a floor, it is checked last, and it outranks every date window.**
 * The ladder below is a calendar, and a calendar can be talked into strange answers: a month with no
 * Sunday backup because the server was down, a schedule changed from daily to weekly the week before
 * an audit, a clock that moved. Any of those can leave the date rules pointing at "two usable
 * database archives", and the same reasoning that gets to two gets to none. So the windows decide
 * what is *eligible*, and the floor then decides what actually goes — newest-first-safe, so the
 * archives that survive are the ones a restore would reach for.
 *
 * **On a `backup.max_storage_gb` breach the job fails loudly and prunes nothing extra.** Pruning
 * past policy to make room converts a disk problem — visible, fixable, somebody's afternoon — into a
 * lost archive that nobody discovers until a restore. The policy prune still runs, because
 * everything in it was already eligible; what never happens is one more file deleted to get under a
 * number.
 *
 * Retention classes are constants here rather than an enum, exactly as §3 requires: they are a
 * policy parameter read from settings, not a domain status with a colour.
 */
final class BackupRetentionService
{
    use WritesAuditTrail;

    /**
     * An ordinary run that is not its day's representative. Kept for
     * `backup.retention_keep_all_days`, whatever bucket its date falls in.
     */
    public const CLASS_TRANSIENT = 'transient';

    public const CLASS_DAILY = 'daily';

    public const CLASS_WEEKLY = 'weekly';

    public const CLASS_MONTHLY = 'monthly';

    public const CLASS_YEARLY = 'yearly';

    /**
     * The fixed list `backup_runs.retention_class` is validated against (§3).
     */
    public const CLASSES = [
        self::CLASS_TRANSIENT,
        self::CLASS_DAILY,
        self::CLASS_WEEKLY,
        self::CLASS_MONTHLY,
        self::CLASS_YEARLY,
    ];

    /**
     * 1024³. Written out so nobody has to decide whether the ceiling is a gigabyte or a gibibyte:
     * it is what the operating system's file listing calls a GB, which is what the operator will
     * compare it against.
     */
    private const BYTES_PER_GB = '1073741824';

    public function __construct(
        private readonly BackupPathResolver $paths,
    ) {}

    /**
     * The class a run is stamped with at creation (§2.1, §6.10.3).
     *
     * The ladder is read longest-window-first, and that order is the whole of it: 1 January is also
     * the first of a month and may also be a Sunday, and an archive that got classified `weekly`
     * because the `if` ran in the other order is an archive that disappears eleven years before the
     * policy said it would.
     *
     * The **first** run of a calendar day is that day's representative; a second run the same day is
     * `transient`. That rule is decided without hindsight on purpose — a class stamped at creation
     * cannot depend on backups that have not happened yet, and `twice_daily` would otherwise have to
     * rewrite the morning's row in the afternoon.
     */
    public function classify(BackupRun $run): string
    {
        $at = $this->runDate($run);

        if ($this->dayAlreadyRepresented($run, $at)) {
            return self::CLASS_TRANSIENT;
        }

        if ($at->month === 1 && $at->day === 1) {
            return self::CLASS_YEARLY;
        }

        if ($at->day === 1) {
            return self::CLASS_MONTHLY;
        }

        if ($at->isSunday()) {
            return self::CLASS_WEEKLY;
        }

        return self::CLASS_DAILY;
    }

    /**
     * The date after which a run's file becomes eligible for pruning.
     *
     * **Every window has `retention_keep_all_days` as its floor.** A daily window shortened to less
     * than the keep-everything window would otherwise make yesterday's archive eligible today, which
     * is not what either setting says and is not a state the two of them together should be able to
     * describe.
     */
    public function retentionUntil(string $class, CarbonInterface $from): Carbon
    {
        $from = Carbon::instance($from)->startOfDay();
        $keepAll = $this->positiveInt('backup.retention_keep_all_days', 7, 1);

        $until = match ($class) {
            self::CLASS_YEARLY => $this->yearlyUntil($from),
            self::CLASS_MONTHLY => $from->copy()->addMonths($this->positiveInt('backup.retention_monthly_months', 12, 1)),
            self::CLASS_WEEKLY => $from->copy()->addWeeks($this->positiveInt('backup.retention_weekly_weeks', 12, 2)),
            self::CLASS_DAILY => $from->copy()->addDays($this->positiveInt('backup.retention_daily_days', 30, 7)),
            default => $from->copy()->addDays($keepAll),
        };

        $floor = $from->copy()->addDays($keepAll);

        return $until->lessThan($floor) ? $floor : $until;
    }

    /**
     * What a prune would do. No side effect of any kind — this is what `--dry-run` and the UI's
     * "Preview prune" button both render, and it is the same computation `prune()` executes.
     */
    public function plan(): RetentionPlan
    {
        $today = Carbon::today();
        $minimum = max(1, $this->positiveInt('backup.retention_min_copies', 3, 1));

        $rows = $this->archivesWithFiles();

        /** @var array<int, string> $reasons id => reason */
        $reasons = [];
        /** @var list<int> $pruneIds */
        $pruneIds = [];

        foreach ($rows as $run) {
            if ($run->trigger->isProtectedFromPruning()) {
                // §6.10.3 rule 3. A pre-restore or pre-deploy archive is the way back from the act
                // it was taken for; a schedule must never be the thing that removed it.
                $reasons[(int) $run->getKey()] = RetentionPlan::REASON_PROTECTED_TRIGGER;

                continue;
            }

            $until = $run->retention_until;

            if ($until === null || Carbon::instance($until)->startOfDay()->greaterThanOrEqualTo($today)) {
                $reasons[(int) $run->getKey()] = RetentionPlan::REASON_INSIDE_WINDOW;

                continue;
            }

            $reasons[(int) $run->getKey()] = RetentionPlan::REASON_WINDOW_EXPIRED;
            $pruneIds[] = (int) $run->getKey();
        }

        // Newest first, so every rescue below takes back the archive a restore would reach for.
        $usableDatabaseIds = $rows
            ->filter(fn (BackupRun $run): bool => $this->isUsableDatabaseArchive($run))
            ->map(fn (BackupRun $run): int => (int) $run->getKey())
            ->values()
            ->all();

        // §6.10.3 rule 2, stated separately from the floor rather than folded into it: the newest
        // usable database archive is never pruned, and it must stay unprunable even if somebody
        // manages to write a minimum of zero into the settings table behind the validator's back.
        $newestDatabaseId = $usableDatabaseIds[0] ?? null;

        if ($newestDatabaseId !== null && in_array($newestDatabaseId, $pruneIds, true)) {
            $pruneIds = array_values(array_diff($pruneIds, [$newestDatabaseId]));
            $reasons[$newestDatabaseId] = RetentionPlan::REASON_NEWEST_DATABASE;
        }

        // §6.10.3 rule 1, applied LAST and outranking every date window above. See the class note.
        $remaining = count(array_diff($usableDatabaseIds, $pruneIds));

        foreach ($usableDatabaseIds as $id) {
            if ($remaining >= $minimum) {
                break;
            }

            if (! in_array($id, $pruneIds, true)) {
                continue;
            }

            $pruneIds = array_values(array_diff($pruneIds, [$id]));
            $reasons[$id] = RetentionPlan::REASON_MINIMUM_COPIES;
            $remaining++;
        }

        $prune = [];
        $keep = [];
        $reclaimable = '0';

        foreach ($rows as $run) {
            $id = (int) $run->getKey();
            $entry = $this->describeRow($run, $reasons[$id] ?? RetentionPlan::REASON_INSIDE_WINDOW);

            if (in_array($id, $pruneIds, true)) {
                $prune[] = $entry;
                $reclaimable = bcadd($reclaimable, (string) $entry['size_bytes'], 0);

                continue;
            }

            $keep[] = $entry;
        }

        $used = $this->paths->usedBytes();
        $ceiling = $this->storageCeilingBytes();

        return new RetentionPlan(
            prune: $prune,
            keep: $keep,
            usableDatabaseArchives: count($usableDatabaseIds),
            usableDatabaseArchivesAfter: $remaining,
            minimumCopies: $minimum,
            usedBytes: $used,
            reclaimableBytes: $reclaimable,
            ceilingBytes: $ceiling,
            ceilingBreached: bccomp(bcsub($used, $reclaimable, 0), $ceiling, 0) > 0,
        );
    }

    /**
     * Execute the plan: files only, row history untouched.
     *
     * A file that will not delete leaves its row alone and is reported. Marking a row pruned when
     * the bytes are still there would make the storage figure a fiction, and the next ceiling check
     * would be computed from it.
     *
     * @throws RuntimeException when the disk is still over `backup.max_storage_gb` afterwards
     */
    public function prune(?User $actor = null): RetentionResult
    {
        $plan = $this->plan();

        $prunedIds = [];
        $failures = [];
        $reclaimed = '0';

        foreach ($plan->prune as $entry) {
            $run = BackupRun::query()->find($entry['id']);

            if ($run === null) {
                continue;
            }

            try {
                $deleted = $this->paths->deleteFile($run);
            } catch (Throwable $exception) {
                $deleted = false;
                $failures[] = [
                    'id' => (int) $entry['id'],
                    'path' => (string) $entry['path'],
                    'error' => mb_substr($exception->getMessage(), 0, 300),
                ];

                continue;
            }

            if (! $deleted && $this->paths->exists($run)) {
                $failures[] = [
                    'id' => (int) $entry['id'],
                    'path' => (string) $entry['path'],
                    'error' => 'The file is still on disk after the delete call.',
                ];

                continue;
            }

            $this->markPruned($run, $actor);

            $prunedIds[] = (int) $entry['id'];
            $reclaimed = bcadd($reclaimed, (string) $entry['size_bytes'], 0);
        }

        $result = new RetentionResult(
            plan: $plan,
            prunedIds: $prunedIds,
            failures: $failures,
            reclaimedBytes: $reclaimed,
        );

        $this->auditPrune($result, $actor);

        // The ceiling is judged on what is left, not on what was there: the policy prune above may
        // well have resolved the breach, and failing on a figure that is no longer true would send
        // an operator looking for a disk problem that had already gone away.
        $remaining = bcsub($plan->usedBytes, $reclaimed, 0);

        if (bccomp($remaining, $plan->ceilingBytes, 0) > 0) {
            throw new RuntimeException(sprintf(
                'Backup storage is over the ceiling: %s used of %s allowed after pruning %d archive(s) by policy. '
                .'The prune stops here rather than deleting past the retention policy — free disk space, raise '
                .'backup.max_storage_gb, or shorten the retention windows, and run it again.',
                RetentionPlan::humanBytes($remaining),
                RetentionPlan::humanBytes($plan->ceilingBytes),
                count($prunedIds),
            ));
        }

        return $result;
    }

    /**
     * Usable database archives that still have a file, newest first.
     *
     * The go-live checklist and the floor above both ask this question, and "usable" means exactly
     * `BackupStatus::isUsable()` — a failed run with a file on disk is not a copy of anything.
     *
     * @return Collection<int, BackupRun>
     */
    public function usableDatabaseArchives(): Collection
    {
        return $this->archivesWithFiles()
            ->filter(fn (BackupRun $run): bool => $this->isUsableDatabaseArchive($run))
            ->values();
    }

    /**
     * `backup.max_storage_gb` in bytes, as an integer string.
     *
     * bcmath, not a float: the setting is a `decimal(_,2)` string and `20.00 * 1024 ** 3` is the
     * multiplication a float turns into 21474836479.999996.
     */
    public function storageCeilingBytes(): string
    {
        $configured = setting('backup.max_storage_gb', '20');
        $gigabytes = is_scalar($configured) ? (string) $configured : '20';

        if (! is_numeric($gigabytes) || bccomp($gigabytes, '0', 2) <= 0) {
            $gigabytes = '20';
        }

        return bcmul($gigabytes, self::BYTES_PER_GB, 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Every run that still points at a file, newest first.
     *
     * `COALESCE(started_at, created_at)` because a row is written before the dump starts and a run
     * killed in its first second has no `started_at` to sort by — and the ordering decides which
     * archives the floor rescues, so it may not be arbitrary.
     *
     * @return Collection<int, BackupRun>
     */
    private function archivesWithFiles(): Collection
    {
        return BackupRun::query()
            ->whereNull('file_pruned_at')
            ->whereNotNull('path')
            ->orderByRaw('COALESCE(started_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();
    }

    private function isUsableDatabaseArchive(BackupRun $run): bool
    {
        return $run->type->includesDatabase() && $run->status->isUsable();
    }

    /**
     * @return array{id: int, uuid: string, type: string, trigger: string, status: string, path: string, size_bytes: int, retention_class: string, retention_until: string|null, created_at: string|null, reason: string}
     */
    private function describeRow(BackupRun $run, string $reason): array
    {
        // The size on disk is preferred over the recorded one: a truncated archive frees what it
        // actually occupies, and the plan's arithmetic has to match what the disk will report after.
        $size = $this->paths->sizeOf($run) ?? (int) ($run->size_bytes ?? 0);

        return [
            'id' => (int) $run->getKey(),
            'uuid' => (string) $run->uuid,
            'type' => $run->type->value,
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'path' => (string) $run->path,
            'size_bytes' => $size,
            'retention_class' => (string) $run->retention_class,
            'retention_until' => $run->retention_until?->toDateString(),
            'created_at' => $this->runDate($run)->toDateTimeString(),
            'reason' => $reason,
        ];
    }

    /**
     * Stamp the row as pruned, inside a transaction like every other write to this table.
     *
     * **A failed run keeps its `failed` status.** Only a completed archive becomes `pruned`:
     * overwriting `failed` here would erase the evidence that a backup did not work on a night
     * somebody may later need to ask about, and the file being gone is already recorded by
     * `file_pruned_at`.
     */
    private function markPruned(BackupRun $run, ?User $actor): void
    {
        DB::transaction(function () use ($run, $actor): void {
            $attributes = [
                'file_pruned_at' => Carbon::now(),
                'pruned_by' => $actor?->getKey(),
            ];

            if ($run->status === BackupStatus::Completed) {
                $attributes['status'] = BackupStatus::Pruned;
            }

            $run->forceFill($attributes)->save();
        });
    }

    private function auditPrune(RetentionResult $result, ?User $actor): void
    {
        if ($result->count() === 0 && ! $result->hasFailures()) {
            return;
        }

        $subject = BackupRun::query()->find($result->prunedIds[0] ?? null);

        if ($subject === null) {
            return;
        }

        $this->audit(
            $subject,
            sprintf('Pruned %d backup archive file(s)', $result->count()),
            [
                'pruned' => $result->prunedIds,
                'reclaimed_bytes' => $result->reclaimedBytes,
                'failures' => $result->failures,
                'usable_database_archives_after' => $result->plan->usableDatabaseArchivesAfter,
                'minimum_copies' => $result->plan->minimumCopies,
            ],
            'backups',
            $actor === null ? 'Scheduled retention run' : null,
        );
    }

    /**
     * When a run happened, for classification and ordering.
     */
    private function runDate(BackupRun $run): Carbon
    {
        $at = $run->started_at ?? $run->created_at;

        return $at === null ? Carbon::now() : Carbon::instance($at);
    }

    /**
     * Whether this run's calendar day already has a representative of the same type.
     *
     * A run that has not been saved yet has no key to exclude, which is the normal case: `classify()`
     * is called while the row is being built.
     */
    private function dayAlreadyRepresented(BackupRun $run, Carbon $at): bool
    {
        $start = $at->copy()->startOfDay();
        $end = $start->copy()->addDay();

        return BackupRun::query()
            ->where('type', $run->type->value)
            ->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Running->value])
            ->whereRaw('COALESCE(started_at, created_at) >= ?', [$start])
            ->whereRaw('COALESCE(started_at, created_at) < ?', [$end])
            ->when($run->exists, fn ($query) => $query->whereKeyNot($run->getKey()))
            ->exists();
    }

    /**
     * A positive integer setting, with a floor. A retention window read as 0 from a malformed row
     * would make every archive eligible the moment it was written.
     */
    private function positiveInt(string $key, int $default, int $minimum): int
    {
        $value = setting($key, $default);
        $value = is_numeric($value) ? (int) $value : $default;

        return max($minimum, $value);
    }

    /**
     * The yearly window, honouring the setting's documented zero: "0 means the monthly window is the
     * end of the history".
     */
    private function yearlyUntil(Carbon $from): Carbon
    {
        $years = setting('backup.retention_yearly_years', 3);
        $years = is_numeric($years) ? (int) $years : 3;

        if ($years <= 0) {
            return $from->copy()->addMonths($this->positiveInt('backup.retention_monthly_months', 12, 1));
        }

        return $from->copy()->addYears($years);
    }
}
