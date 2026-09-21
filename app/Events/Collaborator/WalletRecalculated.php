<?php

declare(strict_types=1);

namespace App\Events\Collaborator;

use App\DataObjects\Collaborator\WalletSnapshot;
use App\Models\Collaborator\Collaborator;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A wallet cache was rewritten from the ledger (spine §10.1, phase-10-12 §10.1).
 *
 * It carries the snapshot rather than only the collaborator, so a listener reporting drift does not
 * have to derive the balance a second time — which would be a second computation of a figure INV-26
 * says has exactly one definition.
 */
final class WalletRecalculated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Collaborator $collaborator,
        public readonly WalletSnapshot $snapshot,
    ) {}
}
