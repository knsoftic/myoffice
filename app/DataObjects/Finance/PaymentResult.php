<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Models\Finance\ProjectPayment;
use App\Models\Institute\StudentFeePayment;

/**
 * What `PaymentService::record*()` did (phase-10-12 §6.3).
 *
 * `created: false` means the idempotency key had already been used and this is the receipt that was
 * written then. It is the correct answer to a double-clicked Save, and a caller that treats it as a
 * failure would show an error for a receipt that exists and is perfectly good.
 */
final readonly class PaymentResult
{
    public function __construct(
        public StudentFeePayment|ProjectPayment $payment,
        public bool $created,
    ) {}
}
