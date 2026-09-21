<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Finance\Invoice;
use App\Services\Finance\InvoiceService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed, client-facing invoice link — `site.invoices.*` (phase-13 §7.8, §8.12).
 *
 * **It reads one document and does nothing else.** No payment, no comment, no upload, no login prompt,
 * and no other invoice reachable from it. The route is bound on `public_token` (40 random characters)
 * and Laravel's signed-URL check carries the expiry from `finance.invoice_public_link_days`, so a link
 * dies of old age on its own.
 *
 * **Everything that could be a refusal is a 404 instead**: a draft, a cancelled invoice, a rotated
 * token, an expired signature. A 403 would confirm to a stranger that a document exists behind the
 * link they guessed, and the point of the token is that they learn nothing.
 *
 * `markViewed()` is called once — the first time only. Re-stamping it on every refresh would make "when
 * did the client first see this" unanswerable, which is the one question the column exists for.
 */
final class PublicInvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function show(string $token): View
    {
        $invoice = $this->resolve($token);

        $this->invoices->markViewed($invoice);

        return view('site.invoices.show', [
            'invoice' => $invoice,
            'asPdf' => false,
            // The public page has nowhere to go back to, and offering a link into the panel would be a
            // login prompt on a page whose whole point is that no login is needed.
            'backUrl' => null,
        ]);
    }

    /**
     * The PDF is not built in this release — dompdf is not installed — so this answers 404 rather than
     * streaming an empty file. The document is on the page above, and the browser's own print produces
     * paper today.
     */
    public function pdf(string $token): never
    {
        $this->resolve($token);

        abort(Response::HTTP_NOT_FOUND);
    }

    private function resolve(string $token): Invoice
    {
        $invoice = Invoice::query()
            ->where('public_token', $token)
            ->whereNotNull('invoice_number')
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->whereNot('status', InvoiceStatus::Cancelled->value)
            ->with(['items', 'client:id,name,company_name,address,city,country,email,phone,tax_number,billing_address,billing_same_as_address',
                'project:id,code,name', 'paymentMethod:id,name,instructions'])
            ->first();

        abort_if($invoice === null, Response::HTTP_NOT_FOUND);

        return $invoice;
    }
}
