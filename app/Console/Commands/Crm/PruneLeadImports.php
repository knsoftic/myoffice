<?php

declare(strict_types=1);

namespace App\Console\Commands\Crm;

use App\Services\Crm\LeadImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `crm:prune-imports` — daily at 02:30 (phase-05 §10.5).
 *
 * Deletes staged CSVs and error reports older than `crm.import_file_retention_days` and `lead_import_rows` older
 * than `crm.import_row_retention_days`. The import batch rows stay, and **no `leads` row is ever deleted**.
 */
#[AsCommand(name: 'crm:prune-imports')]
final class PruneLeadImports extends Command
{
    protected $signature = 'crm:prune-imports';

    protected $description = 'Remove expired staged import files and import row logs (never a lead)';

    public function handle(LeadImportService $imports): int
    {
        $result = $imports->prune(CarbonImmutable::now());

        $this->info(sprintf('%d file(s) and %d import row log(s) pruned.', $result['files'], $result['rows']));

        return self::SUCCESS;
    }
}
