<?php

declare(strict_types=1);

namespace App\Models\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Enums\BackupType;
use App\Enums\BackupVerificationStatus;
use App\Models\Concerns\Blameable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One backup attempt, and everything later needed to trust it (phase-24-25 §2.1, §6.10).
 *
 * **The row outlives the file, on purpose.** Retention removes archives, never rows (§6.10.3 rule
 * 4): the prune stamps `file_pruned_at`, moves `status` to {@see BackupStatus::Pruned}, and leaves
 * the checksum, the size and the proof counts behind for ever. So "did a backup of the night the
 * wallet drifted exist, and what was in it" stays answerable years after the archive itself was
 * swept. A table that deleted its rows alongside its files would answer that question with
 * silence, and silence reads as "no".
 *
 * **Append-only in the D19 sense, with lifecycle columns that may move.** There is no `deleted_at`;
 * `deleting` throws here and `trg_br_no_delete` throws underneath for the raw query, the console
 * one-liner and the database client. Updates are allowed only within {@see MUTABLE_COLUMNS} — the
 * whitelist of §2.1 — so a run can go `pending → running → completed → pruned` and acquire its
 * checksum, but `uuid`, `type`, `trigger`, `disk`, `reason` and the timestamps of its creation are
 * what they were. **An archive whose recorded type or trigger can be edited after the fact proves
 * nothing**, because the edit is exactly what somebody would do to make a missing backup look
 * present.
 *
 * **{@see isUsable()} is not `status === Completed`.** It is that *and* the file still being on
 * disk, which is a different question once pruning exists, and it is the question the restore
 * screen, the minimum-copies rule of §6.10.3 and the go-live gate all actually mean. Asking it in
 * one place is what keeps those three from drifting apart.
 *
 * @property int $id
 * @property string $uuid
 * @property BackupType $type
 * @property BackupStatus $status
 * @property BackupTrigger $trigger
 * @property string $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property bool $is_encrypted
 * @property bool $includes_env
 * @property string|null $database_name
 * @property int|null $table_count
 * @property int|null $row_count_total
 * @property int|null $file_count
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $duration_seconds
 * @property string|null $error_class
 * @property string|null $error_message
 * @property string $retention_class
 * @property CarbonInterface|null $retention_until
 * @property CarbonInterface|null $file_pruned_at
 * @property int|null $pruned_by
 * @property BackupVerificationStatus $verification_status
 * @property CarbonInterface|null $verified_at
 * @property string|null $verification_notes
 * @property string|null $offsite_disk
 * @property CarbonInterface|null $offsite_copied_at
 * @property string|null $reason
 * @property string|null $app_version
 * @property string|null $php_version
 * @property string|null $notes
 */
final class BackupRun extends Model
{
    use Blameable;
    use HasFactory;

    /**
     * The only columns an UPDATE may touch (§2.1).
     *
     * Everything here is lifecycle — what the run turned out to be — and everything absent is what
     * the run *was asked to be*. See the class note for why that line is where it is.
     *
     * @var list<string>
     */
    public const MUTABLE_COLUMNS = [
        'status',
        'finished_at',
        'duration_seconds',
        'size_bytes',
        'checksum_sha256',
        'path',
        'filename',
        'table_count',
        'row_count_total',
        'file_count',
        'error_class',
        'error_message',
        'retention_class',
        'retention_until',
        'file_pruned_at',
        'pruned_by',
        'verification_status',
        'verified_at',
        'verification_notes',
        'offsite_disk',
        'offsite_copied_at',
        'notes',
        'updated_by',
        'updated_at',
    ];

    /**
     * The retention classes of §6.10.3.
     *
     * Mirrored here for casts and display only — the authority is `BackupRetentionService`, and §3
     * settled that these are a policy parameter rather than a domain status with a colour, which is
     * why `retention_class` is a plain string column and not an enum.
     *
     * @var list<string>
     */
    public const RETENTION_CLASSES = ['transient', 'daily', 'weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'uuid',
        'type',
        'status',
        'trigger',
        'disk',
        'path',
        'filename',
        'size_bytes',
        'checksum_sha256',
        'is_encrypted',
        'includes_env',
        'database_name',
        'table_count',
        'row_count_total',
        'file_count',
        'started_at',
        'finished_at',
        'duration_seconds',
        'error_class',
        'error_message',
        'retention_class',
        'retention_until',
        'file_pruned_at',
        'pruned_by',
        'verification_status',
        'verified_at',
        'verification_notes',
        'offsite_disk',
        'offsite_copied_at',
        'reason',
        'app_version',
        'php_version',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'status' => BackupStatus::class,
            'trigger' => BackupTrigger::class,
            'verification_status' => BackupVerificationStatus::class,
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
            'includes_env' => 'boolean',
            'table_count' => 'integer',
            'row_count_total' => 'integer',
            'file_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'retention_until' => 'date',
            'file_pruned_at' => 'datetime',
            'verified_at' => 'datetime',
            'offsite_copied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
        | The model half of the append-only guarantee (D19). `trg_br_no_delete` is the other half and
        | the one that cannot be bypassed; this exists so the failure is a readable exception at the
        | line that made the mistake, rather than a MySQL error 1644 from three layers down.
        */
        static::deleting(static function (self $run): void {
            throw new RuntimeException(sprintf(
                'backup_runs is append-only: run %s may not be deleted. Retention removes the '
                .'archive and stamps file_pruned_at; the record of the backup is kept for ever '
                .'(phase-24-25 §6.10.3 rule 4, D19).',
                $run->uuid ?? 'new',
            ));
        });

        /*
        | The whitelist of §2.1. `updated_at` is stamped by Eloquent *after* this event fires and so
        | never appears in getDirty() here; `updated_by` is stamped by Blameable on `saving`, which
        | fires before it, and is whitelisted for exactly that reason.
        |
        | phase-24-25 §2.1 names `ImmutableBackupRecordException` for this throw. That class lives in
        | the Ops service namespace, which this slice does not own, so the guard throws a
        | RuntimeException carrying everything that class would have said. The guard is what makes
        | the row evidence; the exception type is one line to change when the class lands.
        */
        static::updating(static function (self $run): void {
            $touched = array_keys($run->getDirty());
            $forbidden = array_values(array_diff($touched, self::MUTABLE_COLUMNS));

            if ($forbidden === []) {
                return;
            }

            throw new RuntimeException(sprintf(
                'backup_runs is append-only apart from its lifecycle columns: run %s may not change '
                .'%s. Allowed: %s (phase-24-25 §2.1, D19).',
                $run->uuid ?? 'new',
                implode(', ', $forbidden),
                implode(', ', self::MUTABLE_COLUMNS),
            ));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.4)
    |--------------------------------------------------------------------------
    |
    | §2.4 fixes the edge list, and there is deliberately no `BackupRun` → `BackupRun` edge: a
    | pre-restore copy is linked only from the restore row, so the graph stays acyclic. `pruned_by`
    | likewise has no relation here — the prune job reads the id, and the screens that need the name
    | resolve it through the restore or the activity log.
    */

    /**
     * The restores that used this archive.
     *
     * @return HasMany<BackupRestore, $this>
     */
    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestore::class, 'backup_run_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Archives a restore could actually read — see {@see isUsable()}.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('status', BackupStatus::Completed->value)
            ->whereNull('file_pruned_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, BackupType|string $type): Builder
    {
        return $query->where('type', $type instanceof BackupType ? $type->value : $type);
    }

    /**
     * The archives the minimum-copies rule counts (§6.10.3 rule 1): usable, and containing a dump.
     *
     * `files` archives are excluded by {@see BackupType::includesDatabase()} rather than by a
     * literal list of types, so a type added later is counted correctly or not at all — never
     * counted wrongly.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableDatabaseArchives(Builder $query): Builder
    {
        return $query->usable()->whereIn('type', array_map(
            static fn (BackupType $type): string => $type->value,
            array_filter(
                BackupType::cases(),
                static fn (BackupType $type): bool => $type->includesDatabase(),
            ),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Is there an archive on disk a restore could read?
     *
     * `Completed` **and** not pruned — see the class note. Both halves matter: a pruned row still
     * carries its checksum and its counts, and reads as a perfectly good backup to anything that
     * only looks at the status.
     */
    public function isUsable(): bool
    {
        return $this->status->isUsable() && $this->file_pruned_at === null;
    }

    /**
     * Does this row satisfy the go-live checklist (§6.13)?
     *
     * The archive has to be there *and* to have been restored somewhere to prove it (HD-6). A
     * checksum is not a proof of restorability, and a row that never reached
     * {@see BackupVerificationStatus::RestoreOk} is not a backup for this purpose.
     */
    public function satisfiesGoLive(): bool
    {
        return $this->isUsable() && $this->verification_status->satisfiesGoLive();
    }

    /**
     * Must retention leave this archive alone? (§6.10.3 rule 3.)
     */
    public function isProtectedFromPruning(): bool
    {
        return $this->trigger->isProtectedFromPruning();
    }

    /**
     * Has the offsite copy been written? The 3-2-1 half of §6.10.1.
     */
    public function isCopiedOffsite(): bool
    {
        return $this->offsite_disk !== null && $this->offsite_copied_at !== null;
    }

    /**
     * A one-line account, for a console table or a notification.
     *
     * The division is a byte-to-megabyte display conversion, not arithmetic on a money column —
     * CLAUDE.md §1 rule 4 governs `decimal(15,2)`, and `size_bytes` is an integer count of bytes.
     */
    public function summary(): string
    {
        return sprintf(
            '%s %s — %s%s%s',
            $this->type->label(),
            $this->status->label(),
            $this->filename ?? '(no archive)',
            $this->size_bytes === null ? '' : sprintf(', %.1f MB', $this->size_bytes / 1048576),
            $this->duration_seconds === null ? '' : sprintf(', %ds', $this->duration_seconds),
        );
    }
}
