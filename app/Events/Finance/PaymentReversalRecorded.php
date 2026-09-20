<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\Finance\PaymentReversal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money was sent back, and the row recording it is committed (spine §10.1, §6.6).
 *
 * Listeners: `QueueCommissionReversal` when the reversal needs no approval, and
 * `NotifyReversalApprovers` when it does. The commission job is dispatched by **one** of those two
 * paths and never by both — a reversal awaiting approval has not undone anything yet.
 */
final class PaymentReversalRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentReversal $reversal,
    ) {}
}
