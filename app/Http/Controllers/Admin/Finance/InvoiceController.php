<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\DataObjects\Finance\InvoiceData;
use App\Enums\DiscountMode;
use App\Enums\InvoiceStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\StoreInvoiceRequest;
use App\Http\Requests\Admin\Finance\UpdateInvoiceRequest;
use App\Models\Crm\Client;
use App\Models\Finance\Invoice;
use App\Models\Finance\PaymentMethodOption;
use App\Models\Finance\ProjectPayment;
use App\Models\Project\Project;
use App\Services\Finance\PaymentMethodService;
use App\Services\Finance\InvoiceCalculator;
use App\Services\Finance\InvoiceService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\FinanceVisibility;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices — `admin.invoices.*` (phase-13 §7.1, §8).
 *
 * **Nothing here computes a figure.** `InvoiceService` owns the arithmetic, the number, the status and
 * the three money caches; the controller collects a form, calls it, and renders what comes back. The
 * live totals preview runs the same `InvoiceCalculator` the service runs, which is what makes the
 * number on the screen and the number in the database the same number rather than two that agree.
 *
 * **Every money column is gated separately** (§4.5 rule 2). `invoices.view_any` opens the register;
 * `invoices.view_financial` fills in the figures, and a reader without it gets a list with no amounts —
 * absent columns, not blank cells.
 */
final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    public function index(Request $request): View
    {
        $fields = FinanceVisibility::for($request->user(), 'invoices');

        $invoices = $this->filtered($request)
            ->with(['client:id,name,company_name', 'project:id,code,name'])
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'fields' => $fields,
            'statuses' => InvoiceStatus::cases(),
            'range' => $this->range($request),
            'clients' => $this->clientOptions(),
            // Only for somebody who may see an amount: a count is harmless, a total is not.
            'outstanding' => $fields->seesMoney
                ? Money::of((string) ($this->filtered($request)->reorder()->toBase()
                    ->whereIn('status', [
                        InvoiceStatus::Sent->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value,
                    ])->sum('balance_amount') ?: Money::ZERO))
                : null,
            'overdueCount' => (clone $this->filtered($request))->where('status', InvoiceStatus::Overdue->value)->count(),
            'canCreate' => (bool) $request->user()?->can('invoices.create'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.invoices.create', $this->formData($request));
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->invoices->create(InvoiceData::fromRequest($request), $request->user());

        return redirect()->route('admin.invoices.show', $invoice)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s saved as a draft. It has no number until it is issued — an abandoned '
                .'draft leaves no gap in the series.', $invoice->draft_reference),
        ]);
    }

    /**
     * The live totals, computed and **not** saved.
     *
     * The same calculator the service uses, so the figure the person typing sees is the figure that
     * will be stored. A second implementation for the preview is how a screen and a database start
     * disagreeing about what 1.5 hours at 3,333.33 comes to.
     */
    public function previewTotals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'max:100'],
            'discount_mode' => ['nullable', 'string'],
            'discount_rate' => ['nullable', 'numeric'],
            'discount_fixed' => ['nullable', 'numeric'],
        ]);

        $totals = $this->calculator->compute(
            lines: array_values($validated['lines']),
            invoiceDiscountMode: DiscountMode::tryFrom((string) ($validated['discount_mode'] ?? 'none')) ?? DiscountMode::None,
            invoiceDiscountRate: $validated['discount_rate'] ?? null,
            invoiceDiscountFixed: $validated['discount_fixed'] ?? null,
            roundOff: (bool) setting('finance.invoice_round_off_enabled', false),
            roundingPrecision: (int) setting('finance.invoice_rounding_precision', 1),
        );

        return response()->json([
            'totals' => $totals->columns(),
            'total_discount_amount' => $totals->totalDiscountAmount(),
            'lines' => array_map(
                static fn ($line): array => $line->columns() + ['index' => $line->index],
                $totals->lines,
            ),
        ]);
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $invoice->load([
            'items', 'client', 'project:id,code,name', 'paymentMethod',
            'issuedBy:id,name', 'canceller:id,name', 'replaces:id,invoice_number',
            'payments' => fn ($q) => $q->orderByDesc('paid_on')->orderByDesc('id'),
        ]);

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'fields' => FinanceVisibility::for($request->user(), 'invoices'),
            // Advances this client has paid that nobody has attached to an invoice yet — the list the
            // apply action works from, and the reason the aging report shows a net exposure.
            'unapplied' => ProjectPayment::query()
                ->where('client_id', $invoice->client_id)
                ->whereNull('invoice_id')
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->orderByDesc('paid_on')
                ->limit(20)
                ->get(),
            'canEdit' => (bool) $request->user()?->can('invoices.edit'),
            'canChangeStatus' => (bool) $request->user()?->can('invoices.change_status'),
            'canLink' => (bool) $request->user()?->can('invoices.edit')
                && (bool) $request->user()?->can('project_payments.link_invoice'),
        ]);
    }

    public function edit(Request $request, Invoice $invoice): View
    {
        return view('admin.invoices.edit', array_merge($this->formData($request), [
            'invoice' => $invoice->load('items'),
        ]));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->invoices->update($invoice, InvoiceData::fromRequest($request), $request->user());

        return redirect()->route('admin.invoices.show', $invoice)->with('toast', [
            'type' => 'success',
            'message' => 'The invoice was updated and its totals recomputed.',
        ]);
    }

    /**
     * Only an unissued draft with no payment. The policy decides; this is the message.
     */
    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->invoice_number !== null || $invoice->hasReceipts()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'An issued invoice is never deleted — it is cancelled, and it keeps its '
                    .'number so the series stays explainable.',
            ]);
        }

        $invoice->delete();

        return redirect()->route('admin.invoices.index')->with('toast', [
            'type' => 'success',
            'message' => 'The draft was deleted. It took no invoice number with it.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Moving it along
    |--------------------------------------------------------------------------
    */

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        $issued = $this->invoices->issue($invoice, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Issued as %s. The number is assigned once and never reused.', $issued->invoice_number),
        ]);
    }

    /**
     * Mark it sent, recording who it went to.
     *
     * Emailing is `change_status` rather than an invented `send` ability: it **is** the act that moves
     * a draft to sent, and the spine set that precedent by mapping "run a reconciliation" onto the
     * same case.
     */
    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:10'],
            'recipients.*' => ['required', 'email'],
        ], [
            'recipients.required' => 'An invoice is sent to somebody. Name at least one address.',
        ]);

        $sent = $this->invoices->markSent($invoice, $validated['recipients'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s recorded as sent to %s.', $sent->invoice_number, implode(', ', $validated['recipients'])),
        ]);
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Cancelling an invoice is a decision the client may ask about.',
        ]);

        $this->invoices->cancel($invoice, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is cancelled and keeps its number. Raise a replacement if the work still stands.',
                $invoice->invoice_number ?? $invoice->draft_reference),
        ]);
    }

    public function replace(StoreInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $replacement = $this->invoices->replace($invoice, InvoiceData::fromRequest($request), $request->user());

        return redirect()->route('admin.invoices.show', $replacement)->with('toast', [
            'type' => 'success',
            'message' => sprintf('A replacement draft was created, pointing back at %s.', $invoice->invoice_number),
        ]);
    }

    public function duplicate(Request $request, Invoice $invoice): RedirectResponse
    {
        $copy = $this->invoices->duplicate($invoice, $request->user());

        return redirect()->route('admin.invoices.edit', $copy)->with('toast', [
            'type' => 'success',
            'message' => 'A copy was made as a draft. It carries the lines and none of the history.',
        ]);
    }

    public function rotatePublicLink(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Regenerating the link breaks every copy already sent. Say why.',
        ]);

        $this->invoices->regeneratePublicToken($invoice, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'warning',
            'message' => 'A new link was generated. Every link already emailed has stopped working.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The one spine column this phase touches (D43)
    |--------------------------------------------------------------------------
    */

    public function applyPayment(Request $request, Invoice $invoice, ProjectPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Attaching a receipt changes what this invoice says it has been paid.',
        ]);

        $this->invoices->applyPayment($invoice, $payment, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is now settling this invoice. No commission moved — the engine reads '
                .'the payment, never the invoice.', (string) $payment->payment_no),
        ]);
    }

    public function unapplyPayment(Request $request, Invoice $invoice, ProjectPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->invoices->unapplyPayment($payment, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is detached and back among this client\'s unapplied credits.',
                (string) $payment->payment_no),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Paper
    |--------------------------------------------------------------------------
    */

    /**
     * Reads only stored columns and the invoice's own snapshots, never live settings — so reprinting a
     * year-old invoice reproduces it exactly rather than re-rendering it under today's tax rate.
     */
    public function print(Invoice $invoice): View
    {
        return view('admin.invoices.print', [
            'invoice' => $invoice->load(['items', 'client', 'project:id,code,name', 'paymentMethod']),
            'asPdf' => false,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $fields = FinanceVisibility::for($request->user(), 'invoices');

        return (new CsvWriter)->download(
            'invoices-'.app_date(now(), 'Y-m-d').'.csv',
            // The same field set as the screen: a withheld column is absent from the file too.
            array_map(static fn (string $f): string => ucfirst(str_replace('_', ' ', $f)), $fields->columns()),
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['client:id,name,company_name', 'project:id,code,name']),
                static fn (Invoice $invoice): array => array_values($fields->filter([
                    'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->draft_reference),
                    'client' => (string) ($invoice->client?->company_name ?: $invoice->client?->name),
                    'project' => (string) $invoice->project?->code,
                    'issue_date' => $invoice->issue_date->toDateString(),
                    'due_date' => $invoice->due_date->toDateString(),
                    'status' => $invoice->status->label(),
                    'subtotal_amount' => (string) $invoice->subtotal_amount,
                    'total_discount_amount' => (string) $invoice->total_discount_amount,
                    'tax_amount' => (string) $invoice->tax_amount,
                    'total_amount' => (string) $invoice->total_amount,
                    'paid_amount' => (string) $invoice->paid_amount,
                    'balance_amount' => (string) $invoice->balance_amount,
                ])),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'clients' => $this->clientOptions(),
            'projects' => Project::query()->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'client_id']),
            // One source for every method dropdown (§6.6): a method switched off disappears from
            // every form at once rather than lingering on the one screen nobody filtered.
            'methods' => $this->paymentMethods->availableFor('invoice'),
            'discountModes' => DiscountMode::cases(),
            'taxEnabled' => (bool) setting('finance.tax_enabled', true),
            'taxLabel' => (string) setting('finance.tax_label', 'Tax'),
            'defaultTaxRate' => Money::of((string) setting('finance.default_tax_rate', '0.0000')),
            'paymentTermsDays' => (int) setting('finance.payment_terms_days', 0),
        ];
    }

    private function clientOptions()
    {
        return Client::query()->orderBy('company_name')->orderBy('name')->limit(500)
            ->get(['id', 'client_code', 'name', 'company_name']);
    }

    /**
     * @return Builder<Invoice>
     */
    private function filtered(Request $request): Builder
    {
        $range = $this->range($request);

        return Invoice::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('invoice_number', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhereHas('client', fn ($q) => $q
                        ->where('name', 'like', $term)->orWhere('company_name', 'like', $term)));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('client'), fn (Builder $q) => $q->where('client_id', (int) $request->input('client')))
            ->when($request->filled('project'), fn (Builder $q) => $q->where('project_id', (int) $request->input('project')))
            ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
            // Dated on `issue_date`: the register is about documents raised, and a range on the due
            // date would hide an invoice raised today and due next quarter.
            ->whereBetween('issue_date', [$range->start()->toDateString(), $range->end()->toDateString()]);
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make($request->input('preset'), $request->input('from'), $request->input('to'));
    }
}
