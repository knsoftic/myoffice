<?php

declare(strict_types=1);

namespace App\Jobs\Crm;

use App\Services\Crm\LeadImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The last link of an import's chain (phase-05 §6.6, §10.4): writes the error CSV of invalid and failed rows to the
 * private disk, sets `completed` or `completed_with_errors`, and fires `LeadImportCompleted`, whose listener
 * notifies the importer with the counts and the error-report link.
 */
final class BuildLeadImportErrorReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $importId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LeadImportService $imports): void
    {
        $imports->finish($this->importId);
    }
}
