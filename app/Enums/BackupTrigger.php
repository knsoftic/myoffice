<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a backup was taken (phase-24-25 §2.1, §3, §6.10.3, §6.10.5).
 *
 * **`isProtectedFromPruning()` is the member that matters, and it protects the way back.** A
 * `pre_restore` archive is the state the system was in two minutes before an operator overwrote it
 * (§6.10.5 step 5); a `pre_deploy` archive is the state before a release. Rule 3 of §6.10.3 keeps
 * both out of the prune for ever, because the age-bucket rules would treat a Tuesday-afternoon
 * pre-restore copy as an ordinary daily and delete it on schedule — possibly **while the restore
 * that created it is still running**, which is the one moment the file is the only copy of anything.
 *
 * **`requiresReason()` is only true for `Manual`**, and that asymmetry is deliberate. A scheduled
 * run has a reason — the schedule — and demanding prose from a cron job would put the string
 * "scheduled" in every row and teach readers to skip the column. A human pressing the button is
 * doing something out of the ordinary, and in six months the only person who can explain it is the
 * row.
 */
enum BackupTrigger: string
{
    use HasOptions;

    /** Somebody pressed the button. The Form Request demands a reason (§2.1). */
    case Manual = 'manual';

    case Scheduled = 'scheduled';

    /** The mandatory safety copy taken before a restore (§6.10.5 step 5). Never pruned. */
    case PreRestore = 'pre_restore';

    /** Taken by the deploy runbook before a release (§6.11). Never pruned. */
    case PreDeploy = 'pre_deploy';

    /** A drill: the restore-into-scratch proof, or a test asserting the pipeline works. */
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Scheduled',
            self::PreRestore => 'Before restore',
            self::PreDeploy => 'Before deploy',
            self::Test => 'Test',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'indigo',
            self::Scheduled => 'slate',
            self::PreRestore, self::PreDeploy => 'amber',
            self::Test => 'gray',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manual => 'An operator asked for it, and said why.',
            self::Scheduled => 'The scheduler took it at the configured time.',
            self::PreRestore => 'The safety copy taken before a restore overwrote the database. Never pruned automatically.',
            self::PreDeploy => 'The copy taken before a release. Never pruned automatically.',
            self::Test => 'A drill — the weekly restore proof, or a test of the pipeline itself.',
        };
    }

    /**
     * Must `backup_runs.reason` be filled for this trigger?
     *
     * Enforced by the Form Request rather than a CHECK: the service writes the row `pending` before
     * it knows anything else, and a database constraint would have to be satisfied at that moment
     * by a placeholder — which is how "reason" becomes a column full of the word "backup".
     */
    public function requiresReason(): bool
    {
        return $this === self::Manual;
    }

    /**
     * Does retention have to leave this archive alone?
     *
     * True for the two copies that exist because somebody was about to change something — see the
     * class note. `Test` is deliberately not protected: a drill that filled the disk would stop the
     * backups that are not drills.
     */
    public function isProtectedFromPruning(): bool
    {
        return $this === self::PreRestore || $this === self::PreDeploy;
    }
}
