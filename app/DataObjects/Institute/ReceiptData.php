<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\ReceivedPaymentStatus;
use App\Models\Institute\StudentFeePayment;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One receipt, ready to print (phase-18 §6.7.2).
 *
 * **Both dates are always carried and always labelled.** `paid_on` is when the money arrived and
 * `recorded_at` is when somebody typed it in; a receipt showing one number is a receipt that quietly
 * becomes wrong the moment a back-dated payment is posted, and §120's back-dating test exists because
 * the two genuinely differ.
 *
 * **The balance line has its own timestamp**, because it is recomputed at print time. A receipt
 * reprinted in March must not appear to state January's balance, and R-6 accepts recomputation only on
 * the condition that the document says which moment it describes. Storing a balance snapshot per
 * receipt was the alternative and §109 forbids duplicating financial state.
 *
 * A voided receipt still prints — with a VOID watermark and the reversal number. Refusing to print it
 * would leave whoever is holding the paper copy with no way to find out what happened to it.
 */
final readonly class ReceiptData
{
    public function __construct(
        public StudentFeePayment $payment,
        public CarbonImmutable $printedAt,
        public string $balanceAtPrint,
        public FeeSlipOptions $options,
        /** `payment_reversals.reversal_no` — the document number, not the row id. */
        public ?string $reversalNumber = null,
        public ?string $footerNote = null,
    ) {}

    public function isVoid(): bool
    {
        return $this->payment->status === ReceivedPaymentStatus::Voided;
    }

    public function isRefunded(): bool
    {
        return Money::isPositive((string) $this->payment->refunded_amount);
    }

    /**
     * VOID beats REPRINT: if both are true, the one that matters is that this receipt does not count.
     */
    public function watermark(): ?string
    {
        return $this->isVoid() ? 'VOID' : $this->options->watermark();
    }

    /**
     * True when the value date and the system date disagree — the "back-dated" chip (§8.2, PH18-21).
     */
    public function isBackDated(): bool
    {
        return $this->payment->paid_on !== null
            && $this->payment->recorded_at !== null
            && $this->payment->paid_on->toDateString() !== $this->payment->recorded_at->toDateString();
    }

    /** What the business actually kept from this receipt. */
    public function netReceived(): string
    {
        return (string) $this->payment->net_received_amount;
    }
}
