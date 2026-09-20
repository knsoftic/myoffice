<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\Finance\PaymentReversal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reversal was refused, and the refund it recorded has been rolled back (F-4.9).
 *
 * The money rollback — the payment's `refunded_amount` and its status — already happened inside
 * `rejectReversal()`'s own transaction, under the payment's row lock. **This event never repeats it.**
 * It exists so the caches that assumed the refund recompute from their canonical SQL: an invoice's
 * derived figures, the charge's caches, anything a later phase adds. A listener that adjusted money
 * here would double the rollback.
 */
final class PaymentReversalRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentReversal $reversal,
    ) {}
}
