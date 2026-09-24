<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where one backup attempt got to (phase-24-25 §2.1, §3, §6.10.3).
 *
 * **`Pruned` is a fifth state because the row outlives the file.** Rule 4 of §6.10.3: a
 * `backup_runs` row is never deleted — pruning removes the archive from disk, stamps
 * `file_pruned_at` and moves the status here. Modelling that as "completed, with a date in another
 * column" would mean every "do we still have a backup" query has to read two columns to be right,
 * and the day somebody reads only the status is the day the restore screen offers an archive that
 * is not there any more.
 *
 * **`isUsable()` is true for `Completed` alone, and it is the only place that decision is made.**
 * The restore screen, the prune job's minimum-copies count and the go-live check all ask this
 * rather than each writing its own list of acceptable statuses; three lists drift, and the one that
 * drifts is found by an operator restoring from a failed run.
 */
enum BackupStatus: string
{
    use HasOptions;

    /** The row exists, the archive does not yet. Written before the work starts so a crash leaves a trace. */
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';

    /** The policy removed the file; the row, its checksum and its proof figures stay for ever. */
    case Pruned = 'pruned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Pruned => 'Pruned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Running => 'sky',
            self::Completed => 'emerald',
            self::Failed => 'rose',
            self::Pruned => 'gray',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'Queued. The row was written before the work started, so a crash still leaves a trace.',
            self::Running => 'The archive is being written now.',
            self::Completed => 'The archive was written, sized and checksummed.',
            self::Failed => 'The attempt did not produce an archive. The error class and message are on the row.',
            self::Pruned => 'Retention removed the file. The record of the backup is kept for ever.',
        };
    }

    /**
     * Has this run stopped moving?
     *
     * `Pruned` counts: it is reached from `Completed` by the prune job, and nothing moves out of it.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Pruned;
    }

    /**
     * Is there an archive on disk that a restore could actually read?
     *
     * True for `Completed` and nothing else — see the class note. A `Pruned` row is a record of a
     * backup, not a backup.
     */
    public function isUsable(): bool
    {
        return $this === self::Completed;
    }

    /** Still in flight, so the screen should poll rather than show a verdict. */
    public function isInFlight(): bool
    {
        return ! $this->isTerminal();
    }
}
