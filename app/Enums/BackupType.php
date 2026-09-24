<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which half of the system an archive holds (phase-24-25 §2.1, §3, §6.10.2).
 *
 * **A `files` archive is not a backup of this system, and the retention rules are written against
 * that fact.** Rule 1 of §6.10.3 never leaves fewer than `backup.retention_min_copies` usable
 * *database* archives on disk, and rule 2 never prunes the newest one — a shelf full of `files`
 * archives satisfies neither. {@see includesDatabase()} is the predicate those two rules count
 * with, so the day a fourth type is added the prune job keeps counting the right rows instead of
 * silently treating the new type as a database copy.
 *
 * **`artisanFlags()` exists so that no caller assembles spatie's flags by hand.** `backup:run`
 * accepts `--only-db` and `--only-files`, and passing both produces an archive with neither; a
 * `match` repeated at three call sites is three chances to get that pair wrong, and the archive it
 * writes looks fine until somebody needs it.
 */
enum BackupType: string
{
    use HasOptions;

    /** The dump alone: every table except `backup.excluded_tables`, with triggers, routines and events. */
    case Database = 'database';

    /** `storage/app/public` and `storage/app/private` — the uploads a dump cannot bring back. */
    case Files = 'files';

    /** Both, in one archive. What the weekly run writes and what a restore wants. */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'Database',
            self::Files => 'Files',
            self::Full => 'Full',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Database => 'indigo',
            self::Files => 'cyan',
            self::Full => 'violet',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Database => 'Every table, with the triggers and routines that carry the schema\'s guarantees.',
            self::Files => 'The uploaded documents, media and generated PDFs a database dump cannot bring back.',
            self::Full => 'The database and the files together — the only kind a restore can work from alone.',
        };
    }

    /**
     * Does an archive of this type contain the dump?
     *
     * The predicate the retention minimum and the go-live gate count with — see the class note.
     */
    public function includesDatabase(): bool
    {
        return $this === self::Database || $this === self::Full;
    }

    public function includesFiles(): bool
    {
        return $this === self::Files || $this === self::Full;
    }

    /**
     * The flags `backup:run` needs to produce exactly this type.
     *
     * Shaped for `Artisan::call()` — an option map, not a list of strings — so it matches
     * {@see IntegrityCheckSuite::commandArguments()} and can be spread into the same call. `Full`
     * passes no flag at all, which is spatie's default and the one case where saying nothing is the
     * correct instruction.
     *
     * @return array<string, bool>
     */
    public function artisanFlags(): array
    {
        return match ($this) {
            self::Database => ['--only-db' => true],
            self::Files => ['--only-files' => true],
            self::Full => [],
        };
    }
}
