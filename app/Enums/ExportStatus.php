<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a queued report export has got to (phase-19-23 §3.5, §2.26, §99).
 *
 * **`expired` is a status and not a deleted row.** `reports.export_retention_days` removes the file;
 * the row stays, so somebody who bookmarked a download link is told "that file has expired" rather
 * than "not found", and the record of what was exported, by whom and with which filters survives
 * the bytes. An export is evidence of what left the building.
 *
 * **`failed` carries the real exception text** (`report_exports.error_message`), shown to the person
 * who asked for it. A queued job that failed silently is a person refreshing a page for ten minutes.
 */
enum ExportStatus: string
{
    use HasOptions;

    /** The row exists, the job is on the queue. */
    case Queued = 'queued';

    /** A worker has picked it up. */
    case Running = 'running';

    /** The file is on disk and can be downloaded. */
    case Completed = 'completed';

    /** It did not build. `error_message` says why, in the words the exception used. */
    case Failed = 'failed';

    /** Past `expires_at`: the file is gone, the record of the request is not. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Building',
            self::Completed => 'Ready',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Running => 'sky',
            self::Completed => 'emerald',
            self::Failed => 'rose',
            self::Expired => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Queued => 'Waiting for a worker to pick it up.',
            self::Running => 'Being built now.',
            self::Completed => 'Ready to download.',
            self::Failed => 'It did not build. The reason is on the row.',
            self::Expired => 'The file has been removed; the record of the request remains.',
        };
    }

    /** Only a completed export has a file. */
    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }

    /** Nothing further will happen to it on its own. */
    public function isTerminal(): bool
    {
        return $this !== self::Queued && $this !== self::Running;
    }

    /** Is somebody still waiting? What the screen polls on. */
    public function isPending(): bool
    {
        return ! $this->isTerminal();
    }
}
