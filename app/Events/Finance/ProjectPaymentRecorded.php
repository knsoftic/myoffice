<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\Finance\ProjectPayment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client payment was written and committed (spine §10.1, phase-11).
 *
 * Listeners: `QueueProjectPaymentCommission` (synchronous — it only dispatches the job) and, from
 * Phase 13, the client notification.
 *
 * **After commit, always** (INV-20), and the commission work is never inline: a rolled-back payment
 * must never earn anybody anything, and a queue outage should degrade to "commission pending" rather
 * than to a failed payment for money already in the bank.
 */
final class ProjectPaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ProjectPayment $payment,
    ) {}
}
