<?php

declare(strict_types=1);

namespace App\Jobs\Crm;

use App\Services\Crm\LeadImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * One 200-row chunk of a CSV lead import (phase-05 §6.6 `run()`, §10.4, test 39).
 *
 *   · `ShouldBeUnique` on `lead-import-chunk:{import}:{offset}` for an hour, so a re-dispatch while the chunk is
 *     queued or running is dropped;
 *   · `$afterCommit`, so the chunk never runs before the `processing` status it checks has committed;
 *   · 3 tries with a 10 / 30 / 60 second backoff;
 *   · every row is its own transaction guarded by `uq_lir_row` — a retry after a lost ack re-reads the rows it
 *     already finished as done and moves on, creating nothing twice;
 *   · `failed()` marks the chunk's still-pending rows `failed` with the exception class and leaves the import
 *     resumable.
 */
final class ProcessLeadImportChunk implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public int $timeout = 600;

    public function __construct(
        public readonly int $importId,
        public readonly int $offset,
        public readonly int $size = LeadImportService::CHUNK_SIZE,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return sprintf('lead-import-chunk:%d:%d', $this->importId, $this->offset);
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
        $imports->processChunk($this->importId, $this->offset, $this->size);
    }

    public function failed(?Throwable $exception): void
    {
        app(LeadImportService::class)->failChunk(
            $this->importId,
            $this->offset,
            $this->size,
            $exception ?? new RuntimeException('The import chunk failed.'),
        );
    }
}
