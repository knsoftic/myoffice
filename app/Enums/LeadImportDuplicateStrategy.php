<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What an import does with a line that matches an existing lead (phase-05 §3, `lead_imports.duplicate_strategy`).
 *
 * `update_existing` updates only the fields the CSV actually supplies and never touches `status`, `assigned_to` or a
 * money column (§6.6, test 41).
 */
enum LeadImportDuplicateStrategy: string
{
    use HasOptions;

    case Skip = 'skip';
    case ImportAndFlag = 'import_and_flag';
    case UpdateExisting = 'update_existing';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip duplicates',
            self::ImportAndFlag => 'Import and flag as duplicate',
            self::UpdateExisting => 'Update the existing lead',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Skip => 'slate',
            self::ImportAndFlag => 'amber',
            self::UpdateExisting => 'sky',
        };
    }

    /**
     * The one-sentence explanation the import wizard shows beside each option (§8.6 step 3).
     */
    public function description(): string
    {
        return match ($this) {
            self::Skip => 'A line matching an existing lead is not imported and is listed as skipped.',
            self::ImportAndFlag => 'A matching line is imported as a new lead and linked to the lead it matched.',
            self::UpdateExisting => 'A matching line fills in the fields it supplies on the existing lead; status, assignee and budget are never changed.',
        };
    }
}
