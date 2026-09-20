<?php

declare(strict_types=1);

namespace App\Listeners\Collaborator;

use App\Events\Finance\PaymentReversalApproved;
use App\Events\Finance\PaymentReversalRecorded;
use App\Jobs\Collaborator\ProcessCommissionReversal;

/**
 * Dispatch the reversal job — but only once the refund is actually authorised (spine §6.6).
 *
 * Two events reach here and **exactly one of them fires the job for any given reversal**. A refund
 * that needs no approval is authorised the moment it is recorded; one that does is authorised when
 * somebody approves it, and until then nothing about the partner's commission changes. Undoing
 * commission on a refund that is later refused would mean explaining a reversal of a reversal.
 */
final class QueueCommissionReversal
{
    public function handle(PaymentReversalRecorded|PaymentReversalApproved $event): void
    {
        if (! $event->reversal->mayReverseCommission()) {
            return;
        }

        ProcessCommissionReversal::dispatch((int) $event->reversal->getKey());
    }
}
