<?php

declare(strict_types=1);

namespace App\Events\Collaborator;

use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Commission the partner still held has been taken back (spine §10.1, §6.3).
 *
 * `$debit` is the new negative row; `$original` is the entry it references, unchanged. The partner is
 * notified with both, because "your commission was reduced" is only answerable beside the receipt that
 * was refunded.
 */
final class CommissionReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CollaboratorCommissionLedgerEntry $debit,
        public readonly CollaboratorCommissionLedgerEntry $original,
    ) {}
}
