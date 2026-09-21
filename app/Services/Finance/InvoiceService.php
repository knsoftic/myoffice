<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DataObjects\Finance\InvoiceData;
use App\Enums\DiscountMode;
use App\Enums\InvoiceStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\Finance\ProjectPayment;
use App\Models\User;
use App\Services\Finance\Exceptions\InvoiceRuleException;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Everything that changes an invoice (phase-13 §6.1).
 *
 * **Three columns have exactly one writer each, and it is here.** `paid_amount`, `refunded_amount` and
 * `balance_amount` come from {@see recomputePaid()}'s canonical query over `project_payments`; `status`
 * comes from {@see recomputeStatus()}, a pure function of five stored facts. Nothing anywhere may
 * `increment()` a money cache or assign a status by hand — an invoice and its receipts that disagree
 * cannot be told apart from an invoice and its receipts that agree, until somebody adds them up.
 *
 * **The number is assigned once, inside the issue transaction**, by `DocumentNumberService`, which takes
 * the settings row lock. Two accountants issuing at the same instant serialise on that lock; the loser
 * of a `uq_inv_number` race retries once with the next value. A draft carries no number at all, which is
 * the only way a sequence stays gap-free: an abandoned draft consumes nothing.
 *
 * **Phase 13 writes no payment code.** Money against an invoice is a `project_payments` row the spine
 * inserts; this service only reads it, and the two methods that touch `project_payments.invoice_id` are
 * the single concession D43 grants — narrow, audited, gated on a dedicated ability, and with zero
 * commission effect.
 */
class InvoiceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InvoiceCalculator $calculator,
        private readonly DocumentNumberService $numbers,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Creating and changing
    |--------------------------------------------------------------------------
    */

    /**
     * A new draft: no number, no payments, totals derived from the lines.
     */
    public function create(InvoiceData $data, ?User $actor = null): Invoice
    {
        return $this->db->transaction(function () use ($data, $actor): Invoice {
            $terms = (int) setting('finance.payment_terms_days', 0);
            $issueDate = Carbon::parse($data->issueDate->toDateString(), Format::timezone())->startOfDay();

            $invoice = new Invoice;

            $invoice->forceFill(array_merge($data->headerColumns(), [
                'invoice_number' => null,
                'status' => InvoiceStatus::Draft->value,
                'currency' => Money::currencyCode(),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => ($data->dueDate === null
                    ? $issueDate->copy()->addDays($terms)
                    : Carbon::parse($data->dueDate->toDateString(), Format::timezone()))->toDateString(),
                // Snapshots. A later settings change never rewrites a document the client is holding.
                'payment_terms_days' => $terms,
                'tax_label' => setting('finance.tax_enabled', true) ? setting('finance.tax_label') : null,
                'tax_rate' => setting('finance.tax_enabled', true)
                    ? Money::of((string) setting('finance.default_tax_rate', '0.0000'))
                    : '0.0000',
                'created_by' => $actor?->getKey(),
            ]))->save();

            $this->writeLines($invoice, $data->lines);

            return $this->recalculate($invoice->refresh());
        }, 3);
    }

    /**
     * Replace the content of an invoice that may still be corrected.
     *
     * Refused the moment money has been received against it: at that point the client is holding a
     * document that somebody has paid against, and changing what it says is a different act from
     * correcting a draft. The correct path is cancel and replace.
     */
    public function update(Invoice $invoice, InvoiceData $data, ?User $actor = null): Invoice
    {
        return $this->db->transaction(function () use ($invoice, $data, $actor): Invoice {
            $locked = $this->lock($invoice);

            $this->assertEditable($locked);

            $before = (string) $locked->total_amount;

            $locked->forceFill(array_merge($data->headerColumns(), [
                'issue_date' => Carbon::parse($data->issueDate->toDateString(), Format::timezone())->toDateString(),
                'due_date' => ($data->dueDate === null
                    ? Carbon::parse($data->issueDate->toDateString(), Format::timezone())->addDays((int) $locked->payment_terms_days)
                    : Carbon::parse($data->dueDate->toDateString(), Format::timezone()))->toDateString(),
                // The cached render is of the old figures; keeping it would hand somebody a PDF that
                // disagrees with the screen beside it.
                'pdf_path' => null,
                'updated_by' => $actor?->getKey(),
            ]))->save();

            // Wholesale, inside the transaction: reconciling a line list by id would mean deciding what
            // "the same line" means, and an invoice is short enough that replacing it is honest.
            InvoiceItem::query()->where('invoice_id', $locked->getKey())->delete();

            $this->writeLines($locked, $data->lines);

            $recalculated = $this->recalculate($locked->refresh());

            if (Money::compare($before, (string) $recalculated->total_amount) !== 0) {
                $recalculated->withReason(sprintf('Total changed from %s to %s.',
                    Money::format($before), Money::format((string) $recalculated->total_amount)));
            }

            return $recalculated;
        }, 3);
    }

    /**
     * The twelve steps of §2.7, and nothing else.
     *
     * The five identities are asserted **before** anything is written: an invoice whose header and lines
     * disagree is not a document to save and fix later.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $items = $invoice->items()->get();

        $totals = $this->calculator->compute(
            lines: $items->map(static fn (InvoiceItem $item): array => [
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount_mode' => $item->discount_mode->value,
                'discount_rate' => $item->discount_rate,
                'discount_fixed' => $item->discount_fixed,
                'is_taxable' => (bool) $item->is_taxable,
                'tax_rate' => (string) $item->tax_rate,
            ])->values()->all(),
            invoiceDiscountMode: $invoice->discount_mode,
            invoiceDiscountRate: $invoice->discount_rate === null ? null : (string) $invoice->discount_rate,
            invoiceDiscountFixed: $invoice->discount_fixed === null ? null : (string) $invoice->discount_fixed,
            roundOff: (bool) setting('finance.invoice_round_off_enabled', false),
            roundingPrecision: (int) setting('finance.invoice_rounding_precision', 1),
        );

        $totals->assertBalances();

        foreach ($totals->lines as $result) {
            $item = $items->get($result->index);

            if ($item === null) {
                continue;
            }

            $this->db->table('invoice_items')
                ->where('id', $item->getKey())
                ->update(array_merge($result->columns(), ['updated_at' => now()]));
        }

        $this->db->table('invoices')
            ->where('id', $invoice->getKey())
            ->update(array_merge($totals->columns(), [
                'balance_amount' => Money::sub($totals->totalAmount, (string) $invoice->paid_amount),
                'pdf_path' => null,
                'updated_at' => now(),
            ]));

        return $this->recomputeStatus($invoice->refresh());
    }

    /*
    |--------------------------------------------------------------------------
    | Issuing
    |--------------------------------------------------------------------------
    */

    /**
     * Turn a draft into a real document: assign the number, snapshot the footer, open the public link.
     *
     * A zero invoice cannot be issued, and neither can an empty one — `paid` would then be reachable by
     * arithmetic accident, and a client would receive a bill for nothing.
     */
    public function issue(Invoice $invoice, User $actor, bool $markSent = false): Invoice
    {
        return $this->db->transaction(function () use ($invoice, $actor, $markSent): Invoice {
            $locked = $this->lock($invoice);

            if ($locked->status !== InvoiceStatus::Draft) {
                throw InvoiceRuleException::refuse('status', sprintf(
                    '%s is already issued. A number is assigned once and for ever.', $locked->draft_reference,
                ));
            }

            if ($locked->items()->count() === 0) {
                throw InvoiceRuleException::refuse('lines',
                    'An invoice with no lines is not a bill. Add what is being charged for first.');
            }

            if (Money::compare((string) $locked->total_amount, Money::ZERO) <= 0) {
                throw InvoiceRuleException::refuse('total_amount',
                    'An invoice for nothing cannot be issued: it would read as paid the moment it was '
                    .'created, and the number would be spent on a document nobody owes anything against.');
            }

            $this->assignNumber($locked);

            $locked->forceFill([
                'issued_at' => now(),
                'issued_by' => $actor->getKey(),
                'sent_at' => $markSent ? now() : $locked->sent_at,
                // Snapshots taken at issue, so a later settings change never alters what was sent.
                'footer_note' => setting('finance.invoice_footer_note'),
                'bank_details' => setting('finance.invoice_show_bank_details', true)
                    ? setting('finance.bank_details')
                    : null,
                'public_token' => $locked->public_token ?? Str::random(40),
                'pdf_path' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $this->recomputeStatus($locked->refresh());
        }, 3);
    }

    /**
     * Record that it went to somebody. Issues first when it is still a draft, in one transaction.
     *
     * `sent_at` is stamped only the first time: it is what `recomputeStatus()` reads to decide the
     * invoice is no longer a draft, and re-stamping it on the third reminder would make "when did this
     * become a real claim" unanswerable.
     *
     * @param  list<string>  $recipients
     */
    public function markSent(Invoice $invoice, array $recipients, User $actor): Invoice
    {
        return $this->db->transaction(function () use ($invoice, $recipients, $actor): Invoice {
            $locked = $this->lock($invoice);

            if ($locked->status === InvoiceStatus::Draft) {
                $locked = $this->lock($this->issue($locked, $actor));
            }

            if ($locked->status === InvoiceStatus::Cancelled) {
                throw InvoiceRuleException::refuse('status',
                    'A cancelled invoice is not sent again. Replace it and send the replacement.');
            }

            $locked->forceFill([
                'sent_at' => $locked->sent_at ?? now(),
                'last_sent_at' => now(),
                'last_sent_to' => mb_substr(implode(', ', $recipients), 0, 255),
                'sent_count' => (int) $locked->sent_count + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $this->recomputeStatus($locked->refresh());
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The two derived things
    |--------------------------------------------------------------------------
    */

    /**
     * Rewrite the three money caches from §2.8's canonical query. Idempotent, and the only writer.
     *
     * `net_received_amount` is already net of every reversal, so a partial refund, a full refund and a
     * bounced cheque all reduce `paid_amount` through one path rather than three. A voided receipt is
     * excluded explicitly **and** nets to zero through its own reversal, so the two rules agree.
     */
    public function recomputePaid(Invoice $invoice): Invoice
    {
        $row = $this->db->table('project_payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->selectRaw('COALESCE(SUM(net_received_amount), 0) as paid')
            ->selectRaw('COALESCE(SUM(refunded_amount), 0) as refunded')
            ->first();

        $paid = Money::of((string) ($row->paid ?? Money::ZERO));

        $this->db->table('invoices')->where('id', $invoice->getKey())->update([
            'paid_amount' => $paid,
            'refunded_amount' => Money::of((string) ($row->refunded ?? Money::ZERO)),
            // May be negative: a client who overpaid is holding a credit, and the register shows it.
            'balance_amount' => Money::sub((string) $invoice->total_amount, $paid),
            'updated_at' => now(),
        ]);

        return $invoice->refresh();
    }

    /**
     * §2.8's status ladder — a pure function of five stored facts.
     *
     * `overdue` deliberately beats `partial`: an invoice that is part-paid and late is a claim somebody
     * has to chase, and calling it merely "partial" would keep it out of the list where chasing happens.
     */
    public function recomputeStatus(Invoice $invoice): Invoice
    {
        $status = $this->statusFor($invoice);

        if ($status === $invoice->status) {
            return $invoice;
        }

        $this->db->table('invoices')->where('id', $invoice->getKey())->update([
            'status' => $status->value,
            'updated_at' => now(),
        ]);

        return $invoice->refresh();
    }

    /**
     * The single entry point a controller, a listener or the nightly job uses.
     */
    public function recomputeFromPayments(Invoice $invoice): Invoice
    {
        return $this->db->transaction(function () use ($invoice): Invoice {
            $locked = $this->lock($invoice);

            return $this->recomputeStatus($this->recomputePaid($locked));
        }, 3);
    }

    /**
     * What the status would be, without writing it. Exposed so a screen can explain itself.
     */
    public function statusFor(Invoice $invoice, ?Carbon $asOf = null): InvoiceStatus
    {
        $today = ($asOf ?? Carbon::now(Format::timezone()))->startOfDay();

        if ($invoice->cancelled_at !== null) {
            return InvoiceStatus::Cancelled;
        }

        if ($invoice->sent_at === null) {
            return InvoiceStatus::Draft;
        }

        // Includes an overpaid invoice: the balance is negative, which is still settled.
        if (Money::compare((string) $invoice->balance_amount, Money::ZERO) <= 0) {
            return InvoiceStatus::Paid;
        }

        if ($invoice->due_date->startOfDay()->lessThan($today)) {
            return InvoiceStatus::Overdue;
        }

        if (Money::isPositive((string) $invoice->paid_amount)) {
            return InvoiceStatus::Partial;
        }

        return InvoiceStatus::Sent;
    }

    /*
    |--------------------------------------------------------------------------
    | Ending one
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel it, keeping the number for ever.
     *
     * Refused while any money has been received: the correct sequence is to refund the receipts through
     * the spine first. Cancelling over the top of a payment would leave cash in the system that belongs
     * to a document the business says never happened.
     */
    public function cancel(Invoice $invoice, string $reason, User $actor): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Cancelling an invoice is a decision the client may ask about. It is recorded with its '
                .'reason or it is not taken.');
        }

        return $this->db->transaction(function () use ($invoice, $reason, $actor): Invoice {
            $locked = $this->lock($invoice);

            if ($locked->status === InvoiceStatus::Cancelled) {
                return $locked;
            }

            if (Money::isPositive((string) $locked->paid_amount)) {
                throw InvoiceRuleException::refuse('paid_amount', sprintf(
                    '%s has %s received against it. Refund the receipts first — cancelling over the top '
                    .'would leave money in the system belonging to a document the business says never '
                    .'happened.',
                    $locked->draft_reference, Money::format((string) $locked->paid_amount),
                ));
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $this->recomputeStatus($locked->refresh());
        }, 3);
    }

    /**
     * A fresh draft that says what the cancelled one should have said.
     *
     * `replaces_invoice_id` is what makes a correction one story rather than two unrelated documents for
     * the same work — which is the question an auditor asks first.
     */
    public function replace(Invoice $cancelled, InvoiceData $data, ?User $actor = null): Invoice
    {
        if ($cancelled->status !== InvoiceStatus::Cancelled) {
            throw InvoiceRuleException::refuse('status',
                'Only a cancelled invoice is replaced. An invoice that still stands is corrected, not replaced.');
        }

        $replacement = $this->create($data, $actor);

        $this->db->table('invoices')->where('id', $replacement->getKey())->update([
            'replaces_invoice_id' => $cancelled->getKey(),
            'updated_at' => now(),
        ]);

        return $replacement->refresh();
    }

    /**
     * A copy to start from: no number, no payment link, dates reset to today.
     */
    public function duplicate(Invoice $invoice, ?User $actor = null): Invoice
    {
        return $this->db->transaction(function () use ($invoice, $actor): Invoice {
            $today = Carbon::now(Format::timezone())->startOfDay();

            $copy = new Invoice;

            $copy->forceFill([
                'invoice_number' => null,
                'status' => InvoiceStatus::Draft->value,
                'currency' => $invoice->currency,
                'client_id' => $invoice->client_id,
                'project_id' => $invoice->project_id,
                'branch_id' => $invoice->branch_id,
                'title' => $invoice->title,
                'reference' => $invoice->reference,
                'issue_date' => $today->toDateString(),
                'due_date' => $today->copy()->addDays((int) $invoice->payment_terms_days)->toDateString(),
                'payment_terms_days' => $invoice->payment_terms_days,
                // Re-snapshotted from today's settings, because a duplicate is a new document and the
                // tax rate that applied last year is not a fact about this one.
                'tax_label' => setting('finance.tax_enabled', true) ? setting('finance.tax_label') : null,
                'tax_rate' => setting('finance.tax_enabled', true)
                    ? Money::of((string) setting('finance.default_tax_rate', '0.0000'))
                    : '0.0000',
                'discount_mode' => $invoice->discount_mode->value,
                'discount_rate' => $invoice->discount_rate,
                'discount_fixed' => $invoice->discount_fixed,
                'payment_method_id' => $invoice->payment_method_id,
                'notes' => $invoice->notes,
                'internal_notes' => $invoice->internal_notes,
                'created_by' => $actor?->getKey(),
            ])->save();

            foreach ($invoice->items()->get() as $item) {
                $line = new InvoiceItem;

                $line->forceFill(array_merge(
                    $item->only(['sort_order', 'project_milestone_id', 'description', 'details', 'unit',
                        'quantity', 'unit_price', 'discount_mode', 'discount_rate', 'discount_fixed',
                        'is_taxable', 'tax_rate']),
                    ['invoice_id' => $copy->getKey(), 'created_by' => $actor?->getKey()],
                ))->save();
            }

            return $this->recalculate($copy->refresh());
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The client-facing link
    |--------------------------------------------------------------------------
    */

    /**
     * A new token, which **revokes every link already emailed**.
     */
    public function regeneratePublicToken(Invoice $invoice, string $reason, User $actor): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Regenerating the link breaks every copy already sent. Say why, so the client can be told.');
        }

        $this->db->table('invoices')->where('id', $invoice->getKey())->update([
            'public_token' => Str::random(40),
            'updated_by' => $actor->getKey(),
            'updated_at' => now(),
        ]);

        return $invoice->refresh()->withReason($reason);
    }

    /**
     * Stamped once, the first time a client opens it. Never changes a status: reading a bill is not
     * paying it, and a "viewed" status would put a number on a screen that means nothing financially.
     */
    public function markViewed(Invoice $invoice): void
    {
        if ($invoice->viewed_at !== null) {
            return;
        }

        $this->db->table('invoices')
            ->where('id', $invoice->getKey())
            ->whereNull('viewed_at')
            ->update(['viewed_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | The one spine column this phase touches (D43)
    |--------------------------------------------------------------------------
    */

    /**
     * Attach an advance to an invoice raised later.
     *
     * **The single concession to spine INV-8**, and it is narrow by construction: a conditional UPDATE
     * that must affect exactly one row, so a second concurrent apply affects zero and is refused by
     * name rather than silently overwriting the first. Zero commission effect — the engine keys off the
     * payment, the project and the milestone, and has never read an invoice.
     */
    public function applyPayment(Invoice $invoice, ProjectPayment $payment, string $reason, User $actor): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Attaching a receipt to an invoice changes what that invoice says it has been paid. '
                .'It is recorded with its reason.');
        }

        return $this->db->transaction(function () use ($invoice, $payment, $reason): Invoice {
            $locked = $this->lock($invoice);

            $this->assertLinkable($locked, $payment);

            $claimed = $this->db->table('project_payments')
                ->where('id', $payment->getKey())
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $locked->getKey(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                throw InvoiceRuleException::refuse('payment', sprintf(
                    '%s is already attached to an invoice. A receipt belongs to at most one — a client '
                    .'settling two invoices with one transfer is receipted twice, once against each.',
                    (string) $payment->receipt_no,
                ));
            }

            $payment->refresh()->withReason($reason);

            return $this->recomputeStatus($this->recomputePaid($locked));
        }, 3);
    }

    /**
     * The mirror, with the same permissions and the same mandatory reason.
     */
    public function unapplyPayment(ProjectPayment $payment, string $reason, User $actor): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Detaching a receipt changes what an invoice says it has been paid. It is recorded with its reason.');
        }

        $invoice = Invoice::query()->findOrFail($payment->invoice_id);

        return $this->db->transaction(function () use ($invoice, $payment, $reason): Invoice {
            $locked = $this->lock($invoice);

            $this->assertLinkable($locked, $payment, unlinking: true);

            $released = $this->db->table('project_payments')
                ->where('id', $payment->getKey())
                ->where('invoice_id', $locked->getKey())
                ->update(['invoice_id' => null, 'updated_at' => now()]);

            if ($released !== 1) {
                throw InvoiceRuleException::refuse('payment',
                    'That receipt is no longer attached to this invoice. Nothing was changed.');
            }

            $payment->refresh()->withReason($reason);

            return $this->recomputeStatus($this->recomputePaid($locked));
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(Invoice $invoice, array $lines): void
    {
        foreach (array_values($lines) as $position => $line) {
            $mode = DiscountMode::tryFrom((string) ($line['discount_mode'] ?? 'none')) ?? DiscountMode::None;

            // Tax off at the business level means every new line is created exempt, so the PDF omits
            // the row entirely rather than printing a zero somebody has to interpret.
            $taxable = (bool) setting('finance.tax_enabled', true) && (bool) ($line['is_taxable'] ?? true);

            $item = new InvoiceItem;

            $item->forceFill([
                'invoice_id' => $invoice->getKey(),
                'sort_order' => (int) ($line['sort_order'] ?? $position),
                'project_milestone_id' => $line['project_milestone_id'] ?? null,
                'description' => mb_substr((string) ($line['description'] ?? ''), 0, 255),
                'details' => $line['details'] ?? null,
                'unit' => $line['unit'] ?? null,
                'quantity' => Money::of((string) ($line['quantity'] ?? '1')),
                'unit_price' => Money::of((string) ($line['unit_price'] ?? '0')),
                'discount_mode' => $mode->value,
                'discount_rate' => $mode->requiresRate() ? Money::of((string) ($line['discount_rate'] ?? '0')) : null,
                'discount_fixed' => $mode->requiresAmount() ? Money::of((string) ($line['discount_fixed'] ?? '0')) : null,
                'is_taxable' => $taxable,
                'tax_rate' => $taxable
                    ? Money::of((string) ($line['tax_rate'] ?? $invoice->tax_rate))
                    : '0.0000',
            ])->save();
        }
    }

    /**
     * One retry on a `uq_inv_number` collision, with the next counter value.
     *
     * The unique index is the backstop rather than the mechanism: the settings row lock inside
     * `DocumentNumberService` is what actually serialises two accountants, and a 1062 here means
     * something outside that lock — a restored database, a hand-edited counter — produced a collision.
     */
    private function assignNumber(Invoice $invoice): void
    {
        foreach ([1, 2] as $attempt) {
            $number = $this->numbers->next('finance.invoice_prefix', 'finance.invoice_next_number');

            try {
                $this->db->table('invoices')
                    ->where('id', $invoice->getKey())
                    ->update(['invoice_number' => $number, 'updated_at' => now()]);

                $invoice->setAttribute('invoice_number', $number);

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }
    }

    private function assertEditable(Invoice $invoice): void
    {
        if (! $invoice->status->isEditable()) {
            throw InvoiceRuleException::refuse('status', sprintf(
                '%s is %s and cannot be changed.', $invoice->draft_reference, strtolower($invoice->status->label()),
            ));
        }

        if (Money::isPositive((string) $invoice->paid_amount)) {
            throw InvoiceRuleException::refuse('paid_amount', sprintf(
                '%s has %s received against it. A document somebody has paid against is not corrected in '
                .'place — cancel it and issue a replacement, so both versions exist.',
                $invoice->draft_reference, Money::format((string) $invoice->paid_amount),
            ));
        }

        if ($invoice->status !== InvoiceStatus::Draft && ! setting('finance.invoice_allow_edit_after_issue', true)) {
            throw InvoiceRuleException::refuse('status',
                'This business does not allow an issued invoice to be corrected. Cancel it and issue a replacement.');
        }
    }

    private function assertLinkable(Invoice $invoice, ProjectPayment $payment, bool $unlinking = false): void
    {
        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw InvoiceRuleException::refuse('status',
                'A cancelled invoice does not gain or lose receipts. Attach it to the replacement instead.');
        }

        if ($payment->status === ReceivedPaymentStatus::Voided) {
            throw InvoiceRuleException::refuse('payment',
                'A voided receipt keeps whichever invoice it was attached to, so the document trail '
                .'survives. It is excluded from the paid amount either way.');
        }

        if (! $unlinking && (int) $payment->client_id !== (int) $invoice->client_id) {
            throw InvoiceRuleException::refuse('payment',
                'That receipt is another client\'s money. Attaching it here would show this client as '
                .'having paid something they did not.');
        }
    }

    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
    }
}
