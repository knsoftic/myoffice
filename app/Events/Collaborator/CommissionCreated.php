<?php

declare(strict_types=1);

namespace App\Events\Collaborator;

use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A partner earned something (spine §10.1, phase-10-12 §10.1). Listener: `NotifyCollaboratorOfCommission`.
 *
 * **After commit, always** (INV-20). A rolled-back receipt must never notify anybody that they earned
 * money they did not: the partner reads the notification, the row is not there, and no explanation
 * exists that does not begin with an apology.
 */
final class CommissionCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CollaboratorCommissionLedgerEntry $entry,
    ) {}
}
