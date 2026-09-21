<?php

declare(strict_types=1);

namespace App\Policies\Finance;

use App\Enums\Ability;
use App\Enums\InvoiceStatus;
use App\Models\Finance\Invoice;
use App\Models\User;
use App\Policies\Finance\Concerns\ChecksFinancePermissions;

/**
 * Who may do what to an invoice (phase-13 §4.5, §7.1).
 *
 * The permission opens the door; the **document's own state** decides what is behind it. Those rules
 * are not UI niceties — each one exists because the alternative leaves the business unable to explain
 * a number to a client:
 *
 * - **Delete only an unissued draft with no receipt.** An issued invoice is cancelled, never deleted,
 *   and it keeps its number for ever: a reused number makes two documents answer to one reference.
 * - **Edit only while `finance.invoice_allow_edit_after_issue` permits it, and never once money has
 *   arrived.** Editing under a receipt would change what the client paid against.
 * - **Issue only a numberless draft.** The guard is `invoice_number`, not the status, because an
 *   invoice issued a minute ago is still `draft` until it is sent.
 * - **Cancel nothing that already holds money.** Refund the receipts first; cancelling over the top of
 *   one leaves cash belonging to a document the business says never happened.
 *
 * `applyPayment` is the D43 pair: `invoices.edit` **and** `project_payments.link_invoice`. One without
 * the other is a 403, here and in the route, because hiding a button is not the control.
 */
final class InvoicePolicy
{
    use ChecksFinancePermissions;

    public const MODULE = 'invoices';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if (! $this->holds($user, self::MODULE, Ability::Edit) || $this->isTrashed($invoice)) {
            return false;
        }

        if ($invoice->status === InvoiceStatus::Cancelled) {
            return false;
        }

        // Money has arrived against it: the correction path is cancel and replace, which leaves both
        // documents in the record instead of quietly rewriting the one the client paid.
        if ($invoice->hasReceipts()) {
            return false;
        }

        return $invoice->invoice_number === null
            || (bool) setting('finance.invoice_allow_edit_after_issue', true);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $invoice->invoice_number === null
            && ! $invoice->hasReceipts();
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($invoice)
            && $invoice->invoice_number === null;
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($invoice)
            && $invoice->status !== InvoiceStatus::Cancelled;
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($invoice)
            && $invoice->status !== InvoiceStatus::Cancelled
            && ! $invoice->hasReceipts();
    }

    public function print(User $user, Invoice $invoice): bool
    {
        // Printing shows every figure on the document, so it needs the money permission as well as the
        // print one. A print view is not a way around `view_financial`.
        return $this->holds($user, self::MODULE, Ability::Print)
            && $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export)
            && $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    public function viewFinancial(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewFinancial);
    }

    /**
     * The D43 pair. `project_payments` declares no `edit` and never will, so moving a receipt onto an
     * invoice has its own narrow ability — and it authorises that move and nothing else.
     */
    public function applyPayment(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && $this->holds($user, 'project_payments', Ability::LinkInvoice)
            && ! $this->isTrashed($invoice)
            && $invoice->status !== InvoiceStatus::Cancelled;
    }
}
