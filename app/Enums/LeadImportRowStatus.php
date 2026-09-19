<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The outcome of one CSV line (phase-05 §3, `lead_import_rows.status`).
 *
 * `pending` is written by the dry run for a line that will be imported; the run moves it to `created` or `updated`,
 * or records why it was not (`skipped_duplicate`, `skipped_invalid`, `failed`).
 */
enum LeadImportRowStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Created = 'created';
    case Updated = 'updated';
    case SkippedDuplicate = 'skipped_duplicate';
    case SkippedInvalid = 'skipped_invalid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::SkippedDuplicate => 'Skipped (duplicate)',
            self::SkippedInvalid => 'Skipped (invalid)',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Created => 'emerald',
            self::Updated => 'sky',
            self::SkippedDuplicate => 'amber',
            self::SkippedInvalid => 'orange',
            self::Failed => 'rose',
        };
    }

    /**
     * The line wrote a lead (created or updated one).
     */
    public function isSuccess(): bool
    {
        return $this === self::Created || $this === self::Updated;
    }

    /**
     * The line was deliberately not imported.
     */
    public function isSkipped(): bool
    {
        return $this === self::SkippedDuplicate || $this === self::SkippedInvalid;
    }

    /**
     * The line belongs in the error report (§8.6 "Download error CSV").
     */
    public function isError(): bool
    {
        return $this === self::SkippedInvalid || $this === self::Failed;
    }
}
