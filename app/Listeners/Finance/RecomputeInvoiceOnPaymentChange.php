<?php

declare(strict_types=1);

namespace App\Listeners\Finance;

use App\Events\Finance\PaymentReversalApproved;
use App\Events\Finance\PaymentReversalRecorded;
use App\Events\Finance\PaymentReversalRejected;
use App\Events\Finance\ProjectPaymentRecorded;
use App\Models\Finance\Invoice;
use App\Services\Finance\InvoiceService;

/**
 * Keep an invoice's three caches in step with the receipts against it (phase-13 §10.2, §6.5 step 8).
 *
 * **Phase 13 reacts here; it never drives.** The spine records the money and dispatches the commission
 * work; this listener hears about it afterwards and re-derives `paid_amount`, `refunded_amount`,
 * `balance_amount` and `status` from the canonical query. It computes nothing of its own, so replaying
 * it is harmless — which matters, because a rejected reversal and an approved one both arrive here and
 * the correct response to either is the same: ask the database what is true now.
 *
 * It is deliberately **not** queued. The invoice a cashier just took money against has to read right on
 * the page they land on, and a recompute is two queries under a row lock — cheap enough to do inline,
 * and wrong enough to notice if it lags.
 */
final class RecomputeInvoiceOnPaymentChange
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(
        ProjectPaymentRecorded|PaymentReversalRecorded|PaymentReversalApproved|PaymentReversalRejected $event,
    ): void {
        $invoiceId = $event instanceof ProjectPaymentRecorded
            ? $event->payment->invoice_id
            : $event->reversal->projectPayment?->invoice_id;

        if ($invoiceId === null) {
            // An advance, a fee receipt, or a payment nobody has attached to an invoice yet. There is
            // nothing to recompute, and inventing a link here would be the one thing D43 forbids.
            return;
        }

        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null) {
            return;
        }

        $this->invoices->recomputeFromPayments($invoice);
    }
}
