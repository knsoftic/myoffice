<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;

/**
 * What `LedgerWriter::post()` did (phase-10-12 §6.3).
 *
 * `created: false` is the **normal** answer to a replay, not an error: the sweeper re-queues a row a
 * dead worker left behind, a queue retries after a timeout, an operator replays a `failed_jobs` entry
 * weeks later. Each of those arrives at `uq_cle_dedupe`, finds the row already there, and returns it.
 * A caller that treats a duplicate as a failure would turn every one of those into an alert about
 * nothing.
 */
final readonly class LedgerPostResult
{
    public function __construct(
        public CollaboratorCommissionLedgerEntry $entry,
        public bool $created,
    ) {}
}
