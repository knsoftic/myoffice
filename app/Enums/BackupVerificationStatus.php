<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How far an archive has been proved (phase-24-25 §2.1, §3, §6.10.4).
 *
 * **HD-6: a backup nobody has restored is a hypothesis.** That is the whole reason this is four
 * states and not a boolean. `ChecksumOk` says the bytes on disk are the bytes that were written —
 * it says nothing about whether `mysql` will accept them, whether the dump was truncated at the
 * moment the disk filled, or whether the archive was encrypted with a password nobody still has.
 * Only `RestoreOk` answers the question anybody is actually asking, and it is earned the only way
 * it can be: the weekly deep verification restores the archive into
 * `backup.restore_scratch_database`, runs `migrate:status`, compares the proof counts of §6.10.4,
 * and passes `financial:verify-constraints` and `collaborators:reconcile-wallets` on the restored
 * copy.
 *
 * **`satisfiesGoLive()` is true for `RestoreOk` alone**, and §6.13 turns on it: a `backup_runs` row
 * that never reached it is not a backup for go-live purposes. The gate is one method so that the
 * checklist, the health screen and the `integrity:verify --suite=backup` run cannot each form their
 * own opinion about what "verified" means.
 */
enum BackupVerificationStatus: string
{
    use HasOptions;

    /** Written, never checked. Where every archive starts. */
    case Unverified = 'unverified';

    /** The file on disk still hashes to `checksum_sha256` and opens as a valid zip. */
    case ChecksumOk = 'checksum_ok';

    /** It was restored into the scratch database and the money proved out (HD-6). */
    case RestoreOk = 'restore_ok';

    /** A check ran and did not pass. `verification_notes` says which one. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::ChecksumOk => 'Checksum OK',
            self::RestoreOk => 'Restore proved',
            self::Failed => 'Verification failed',
        };
    }

    /**
     * Amber for `ChecksumOk` on purpose: it is not a green state.
     *
     * An archive whose bytes are intact and which has never been restored is the exact thing HD-6
     * was written about, and a green badge is how it stops being looked at.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unverified => 'slate',
            self::ChecksumOk => 'amber',
            self::RestoreOk => 'emerald',
            self::Failed => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Unverified => 'Nothing has been checked yet.',
            self::ChecksumOk => 'The bytes are intact and the archive opens. Nobody has restored it.',
            self::RestoreOk => 'It was restored into the scratch database, the counts matched and the money proved out.',
            self::Failed => 'A verification ran and did not pass. The notes say which one.',
        };
    }

    /**
     * Does this count as a backup for the go-live checklist (§6.13)?
     *
     * True only for `RestoreOk` — see the class note.
     */
    public function satisfiesGoLive(): bool
    {
        return $this === self::RestoreOk;
    }

    /**
     * Has anything at all been proved about the file?
     *
     * Distinct from {@see satisfiesGoLive()}: the restore wizard refuses a production target whose
     * checksum has not been re-checked (§6.10.5 gate 2), and for that narrower question
     * `ChecksumOk` is enough.
     */
    public function checksumProved(): bool
    {
        return $this === self::ChecksumOk || $this === self::RestoreOk;
    }
}
