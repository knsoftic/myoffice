<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where one restore attempt got to (phase-24-25 §2.2, §3, §6.10.5).
 *
 * **`Aborted` is not `Failed`, and the difference is the first thing an operator needs at two in
 * the morning: was the database written to?** A restore aborts when a gate refuses before step 7 —
 * the checksum did not match, the mandatory pre-restore backup did not reach `completed`, the typed
 * phrase was wrong, somebody stopped it — and in every one of those cases the target database is
 * untouched. A restore *fails* when it ran and something after it did not hold: `migrate` left work
 * pending, the constraints did not pass, reconciliation found structural failures. Collapsing the
 * two into one red state means the person reading the row cannot tell whether they are recovering
 * from a half-written database or simply trying again, and §6.10.5's own instruction for a failure
 * — the application stays down, and the next step is the pre-restore copy — is exactly wrong for an
 * abort.
 *
 * **Nothing moves out of a terminal state.** A second attempt is a second `backup_restores` row,
 * because the table is the evidence that a restore happened and evidence that gets rewritten is not
 * evidence (D19).
 */
enum RestoreStatus: string
{
    use HasOptions;

    /** The row exists and the gates are being walked. Nothing has been written to the target. */
    case Requested = 'requested';

    /** Past step 7: the dump is going in. */
    case Running = 'running';

    /** The schema is forward, the counts are captured and the money proved out (step 10). */
    case Completed = 'completed';

    /** It ran, and something after it did not hold. The application stays down — see the class note. */
    case Failed = 'failed';

    /** A gate refused before anything was written. The target database is as it was. */
    case Aborted = 'aborted';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Aborted => 'Aborted',
        };
    }

    /**
     * Amber rather than red for `Aborted`: nothing was written, so it is not a wound — it is a
     * refusal, and a refusal that worked.
     */
    public function color(): string
    {
        return match ($this) {
            self::Requested => 'slate',
            self::Running => 'sky',
            self::Completed => 'emerald',
            self::Failed => 'rose',
            self::Aborted => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Requested => 'Recorded, gates being walked. The target database has not been touched.',
            self::Running => 'The archive is being written into the target database now.',
            self::Completed => 'Restored, migrated forward, counted and proved.',
            self::Failed => 'It ran and a proof did not hold. The way back is the pre-restore backup.',
            self::Aborted => 'A gate refused before anything was written. The target database is unchanged.',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Aborted;
    }

    /**
     * Was the target database written to?
     *
     * The question the class note is about, asked once here rather than re-derived from a status
     * list at every call site.
     */
    public function touchedTheDatabase(): bool
    {
        return $this === self::Running || $this === self::Completed || $this === self::Failed;
    }
}
