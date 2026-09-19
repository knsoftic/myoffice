<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Models\Crm\LeadImport;
use App\Models\Crm\LeadImportRow;
use Illuminate\Support\Collection;

/**
 * The dry-run result of an import (phase-05 §6.6 `validateRows()`, §8.6 step 4): counts plus the first rows that
 * failed validation, each with its row number, raw values and per-field messages. No lead exists yet.
 */
final readonly class ImportPreview
{
    /**
     * @param  Collection<int, LeadImportRow>  $errorRows
     */
    public function __construct(
        public LeadImport $import,
        public int $totalRows,
        public int $validRows,
        public int $invalidRows,
        public int $duplicateRows,
        public Collection $errorRows,
        public bool $stoppedEarly = false,
    ) {}

    public function canRun(): bool
    {
        return $this->validRows > 0;
    }
}
