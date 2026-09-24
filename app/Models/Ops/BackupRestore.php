<?php

declare(strict_types=1);

namespace App\Models\Ops;

use App\Enums\RestoreStatus;
use App\Enums\RestoreTarget;
use App\Models\Concerns\Blameable;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The record of a restore — the most consequential act an operator can perform (phase-24-25 §2.2,
 * §6.10.5).
 *
 * **This row has to outlive the operator, so nothing about it can be taken back.** Append-only in
 * the D19 sense: no `deleted_at`, `deleting` throws here, `trg_brs_no_delete` throws beneath for
 * the raw query and the database client, and `requested_by` is a **RESTRICT** foreign key rather
 * than `nullOnDelete`. A row saying production was overwritten on a Tuesday with nobody attached to
 * it is not an audit trail, it is an alibi.
 *
 * **The four gates are stamped, not inferred** (§6.10.5): `password_confirmed_at` from the
 * `password.confirm` middleware, `confirmed_at` when the typed phrase matched case-sensitively,
 * `checksum_verified` when the archive re-hashed to what was recorded, and `reason` NOT NULL and
 * validated `min:20`. Storing the stamps rather than recomputing "was this allowed" later is the
 * whole point: six months on, the question is not whether the gates *would* pass now — it is
 * whether they passed then.
 *
 * **`Aborted` and `Failed` are different rows to be reading at two in the morning.** Aborted means
 * a gate refused and the target database is untouched; failed means the restore ran and a proof
 * afterwards did not hold, and §6.10.5's instruction for that case — the application stays down and
 * the next step is the pre-restore copy — is exactly wrong for an abort. See
 * {@see RestoreStatus::touchedTheDatabase()}.
 *
 * **The before/after counts are the proof the restore did not quietly lose anything.**
 * `ledger_rows_before` / `ledger_rows_after` are broken out of the JSON as columns so the financial
 * figure is greppable and indexable rather than buried; {@see ledgerDelta()} is the number somebody
 * looks at first.
 *
 * @property int $id
 * @property string $uuid
 * @property int $backup_run_id
 * @property int|null $pre_restore_backup_run_id
 * @property RestoreTarget $target
 * @property RestoreStatus $status
 * @property string $database_name
 * @property string $reason
 * @property int $requested_by
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $password_confirmed_at
 * @property bool $checksum_verified
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $duration_seconds
 * @property array<string, int>|null $row_counts_before
 * @property array<string, int>|null $row_counts_after
 * @property int|null $ledger_rows_before
 * @property int|null $ledger_rows_after
 * @property array<string, mixed>|null $proof
 * @property string|null $error_class
 * @property string|null $error_message
 * @property string|null $notes
 */
final class BackupRestore extends Model
{
    use Blameable;
    use HasFactory;

    /**
     * The only columns an UPDATE may touch (§2.2).
     *
     * Everything here is what the restore turned into; everything absent is what it was authorised
     * to be. `target`, `database_name`, `reason`, `backup_run_id`, `pre_restore_backup_run_id` and
     * `requested_by` are outside it for ever: **a restore whose recorded target or reason can be
     * edited afterwards documents nothing**, because editing them is precisely what somebody would
     * do about a restore that should not have happened.
     *
     * `row_counts_before` and `ledger_rows_before` are also outside it — they are captured once, at
     * step 6, and a "before" figure that can be rewritten after the fact is not a before figure.
     *
     * @var list<string>
     */
    public const MUTABLE_COLUMNS = [
        'status',
        'confirmed_at',
        'password_confirmed_at',
        'checksum_verified',
        'started_at',
        'finished_at',
        'duration_seconds',
        'row_counts_after',
        'ledger_rows_after',
        'proof',
        'error_class',
        'error_message',
        'notes',
        'updated_by',
        'updated_at',
    ];

    protected $fillable = [
        'uuid',
        'backup_run_id',
        'pre_restore_backup_run_id',
        'target',
        'status',
        'database_name',
        'reason',
        'requested_by',
        'confirmed_at',
        'password_confirmed_at',
        'checksum_verified',
        'started_at',
        'finished_at',
        'duration_seconds',
        'row_counts_before',
        'row_counts_after',
        'ledger_rows_before',
        'ledger_rows_after',
        'proof',
        'error_class',
        'error_message',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => RestoreTarget::class,
            'status' => RestoreStatus::class,
            'checksum_verified' => 'boolean',
            'confirmed_at' => 'datetime',
            'password_confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'row_counts_before' => 'array',
            'row_counts_after' => 'array',
            'ledger_rows_before' => 'integer',
            'ledger_rows_after' => 'integer',
            'proof' => 'array',
        ];
    }

    protected static function booted(): void
    {
        /*
        | The model half of the append-only guarantee (D19); `trg_brs_no_delete` is the half that
        | cannot be bypassed. This one exists so the failure is a readable exception where the
        | mistake was made rather than a MySQL error 1644 from three layers down.
        */
        static::deleting(static function (self $restore): void {
            throw new RuntimeException(sprintf(
                'backup_restores is append-only: restore %s may not be deleted. A restore is the '
                .'most consequential act an operator can perform and its record must outlive the '
                .'operator (phase-24-25 §2.2, D19).',
                $restore->uuid ?? 'new',
            ));
        });

        /*
        | The whitelist of §2.2. `updated_at` is stamped by Eloquent after this event fires and so
        | never shows up in getDirty() here; `updated_by` is stamped by Blameable on `saving`, which
        | fires before it, and is whitelisted for that reason.
        |
        | phase-24-25 §2.2 names `ImmutableBackupRecordException` for this throw. That class belongs
        | to the Ops service namespace, which this slice does not own, so the guard throws a
        | RuntimeException carrying everything that class would have said. The guard is the part
        | that makes the row evidence; the exception type is one line to change when it lands.
        */
        static::updating(static function (self $restore): void {
            $touched = array_keys($restore->getDirty());
            $forbidden = array_values(array_diff($touched, self::MUTABLE_COLUMNS));

            if ($forbidden === []) {
                return;
            }

            throw new RuntimeException(sprintf(
                'backup_restores is append-only apart from its lifecycle columns: restore %s may '
                .'not change %s. Allowed: %s (phase-24-25 §2.2, D19).',
                $restore->uuid ?? 'new',
                implode(', ', $forbidden),
                implode(', ', self::MUTABLE_COLUMNS),
            ));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.4)
    |--------------------------------------------------------------------------
    */

    /**
     * What was restored.
     *
     * @return BelongsTo<BackupRun, $this>
     */
    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }

    /**
     * The safety copy taken first — the way back if this restore went wrong (§6.10.5 step 5).
     *
     * Null only for {@see RestoreTarget::Local}. On any other target this being null while the row
     * is `running` means a gate was skipped.
     *
     * @return BelongsTo<BackupRun, $this>
     */
    public function preRestoreBackup(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'pre_restore_backup_run_id');
    }

    /**
     * Who asked for it — a separate edge from `creator()`, and the one that answers "who did this".
     *
     * `created_by` is bookkeeping about the row and is nullable; `requested_by` is the authorised
     * actor, is NOT NULL, and its foreign key is RESTRICT.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTarget(Builder $query, RestoreTarget|string $target): Builder
    {
        return $query->where('target', $target instanceof RestoreTarget ? $target->value : $target);
    }

    /**
     * The restores that actually wrote to a database — what an audit reads first.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTouchedTheDatabase(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (RestoreStatus $status): string => $status->value,
            array_filter(
                RestoreStatus::cases(),
                static fn (RestoreStatus $status): bool => $status->touchedTheDatabase(),
            ),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Were all four gates of §6.10.5 stamped before this restore ran?
     *
     * Read from the stamps, never re-derived from the current configuration — see the class note.
     * `Local` needs neither the phrase nor a pre-restore copy, which is why the target decides.
     */
    public function gatesSatisfied(): bool
    {
        if ($this->password_confirmed_at === null || ! $this->checksum_verified) {
            return false;
        }

        if ($this->target->requiresConfirmationPhrase() && $this->confirmed_at === null) {
            return false;
        }

        return ! $this->target->requiresPreBackup() || $this->pre_restore_backup_run_id !== null;
    }

    /**
     * Rows the ledger gained or lost across the restore.
     *
     * Null while either side is missing, which is itself the answer during a run. A negative number
     * is the figure this table exists to surface: restoring an older archive legitimately loses
     * ledger rows, and it must never happen without somebody having seen the number.
     */
    public function ledgerDelta(): ?int
    {
        if ($this->ledger_rows_before === null || $this->ledger_rows_after === null) {
            return null;
        }

        return $this->ledger_rows_after - $this->ledger_rows_before;
    }

    /**
     * Was the target database written to? The first question a failed restore raises.
     */
    public function touchedTheDatabase(): bool
    {
        return $this->status->touchedTheDatabase();
    }

    /**
     * A one-line account, for a console table or a notification.
     */
    public function summary(): string
    {
        $delta = $this->ledgerDelta();

        return sprintf(
            '%s restore of %s — %s%s',
            $this->target->label(),
            $this->database_name,
            $this->status->label(),
            $delta === null ? '' : sprintf(', ledger %+d rows', $delta),
        );
    }
}
