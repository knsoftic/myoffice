<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which database a restore is about to overwrite (phase-24-25 §2.2, §3, §6.10.5).
 *
 * **The target is not a label on the row, it is what decides how many gates stand in front of the
 * operator.** `requiresPreBackup()` and `requiresConfirmationPhrase()` are read by
 * `BackupRestoreService` and by the restore wizard, and both are written as "false only for
 * `Local`" rather than "true for staging and production" — so a target added later is guarded by
 * default. The failure mode the other way round is specific and unrecoverable: somebody adds
 * `Demo`, forgets the list, and the first restore into it takes no safety copy of what it replaced.
 *
 * **`Local` is the one exemption, and it is exempt because there is nothing to lose.** A developer
 * restoring last night's dump onto their own machine, twenty times in an afternoon, is the workflow
 * this system exists to make ordinary; demanding a typed phrase and a pre-restore archive each time
 * teaches the operator to type the phrase without reading it, and that habit is what they bring to
 * production.
 */
enum RestoreTarget: string
{
    use HasOptions;

    /** A developer's own database. No safety copy, no phrase — see the class note. */
    case Local = 'local';

    case Staging = 'staging';

    /** The live database. Four gates, none of them skippable from the UI or the console (§6.10.5). */
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local',
            self::Staging => 'Staging',
            self::Production => 'Production',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Local => 'slate',
            self::Staging => 'amber',
            self::Production => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Local => 'A developer machine. Nothing here is anybody\'s only copy.',
            self::Staging => 'A shared rehearsal environment. Treated like production, minus the audience.',
            self::Production => 'The live database. Every gate applies, and the way back is the pre-restore copy.',
        };
    }

    /**
     * Must a `pre_restore` backup be taken and linked before this restore may run?
     *
     * False only for `Local`. Step 5 of §6.10.5 aborts the restore when that backup does not reach
     * `completed`, because the way back from a bad restore is always the archive taken two minutes
     * before it.
     */
    public function requiresPreBackup(): bool
    {
        return $this !== self::Local;
    }

    /**
     * Must the operator type `backup.restore_confirmation_phrase` (case-sensitively) first?
     */
    public function requiresConfirmationPhrase(): bool
    {
        return $this !== self::Local;
    }
}
