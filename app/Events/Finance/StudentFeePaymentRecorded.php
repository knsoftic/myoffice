<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\Institute\StudentFeePayment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student receipt was written and committed (spine §10.1, phase-10-12 §6.1).
 *
 * Listeners: `QueueStudentFeeCommission` (synchronous — it only dispatches the job) and
 * `NotifyStudentOfFeePayment` (queued).
 *
 * **After commit, always** (INV-20), and the commission work is never inline. A cashier's receipt must
 * not wait on the engine, and a queue outage should degrade to "commission pending" rather than to a
 * failed receipt for money that is already in the drawer.
 */
final class StudentFeePaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly StudentFeePayment $payment,
    ) {}
}
