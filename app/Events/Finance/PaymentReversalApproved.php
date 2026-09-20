<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\Finance\PaymentReversal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reversal that needed approval got it (spine §2.18.4, phase-10-12 §6.3).
 *
 * This is what dispatches `ProcessCommissionReversal`. Until it fires, the money has been recorded as
 * going back but nobody has agreed it should, and undoing a partner's commission on an unapproved
 * refund would mean explaining a reversal that was later rejected.
 */
final class PaymentReversalApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentReversal $reversal,
    ) {}
}
