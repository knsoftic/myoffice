<?php

declare(strict_types=1);

namespace App\Events\Collaborator;

use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Commission that had already been **paid out** is now owed back (spine §6.3.5, §6.6).
 *
 * A different event from `CommissionReversed` on purpose: the money left the company, the wallet may
 * legitimately go negative, and new payouts are refused while it is. Telling a partner their balance
 * "was reversed" when what actually happened is that they owe money back would be the wrong sentence
 * at the worst possible moment.
 */
final class CommissionClawedBack implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CollaboratorCommissionLedgerEntry $debit,
        public readonly CollaboratorCommissionLedgerEntry $original,
    ) {}
}
