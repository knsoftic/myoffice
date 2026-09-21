<?php

declare(strict_types=1);

namespace App\Support\Portal\Sections;

use App\Contracts\Portal\ClientPortalDownloadSection;
use App\Contracts\Portal\ClientPortalRecordSection;
use App\Enums\InvoiceStatus;
use App\Models\Crm\Client;
use App\Models\Finance\Invoice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's own invoices (phase-13 §8.12, §9's Client row).
 *
 * Contributed into Phase 5's registry rather than by redeclaring a `client.*` route name (D31). A
 * section the registry does not hold shows no nav item and answers 404, which is how the panel stayed
 * honest before this phase shipped.
 *
 * **{@see COLUMNS} is the security boundary, and it is deliberately an allow-list.** `internal_notes`,
 * `created_by`, `updated_by`, `branch_id`, `replaces_invoice_id`, `public_token` and every cost or
 * commission column are simply **not selected** — absent from the response, not hidden in a template
 * where the next refactor could reveal them.
 *
 * **A draft is never visible.** It carries no number and the business has not committed to it; showing
 * one would let a client see a bill that may never be sent, and quote it back.
 *
 * {@see find()} returns null for anything outside this client, so another client's id is a **404**
 * rather than a 403 that would confirm the invoice exists.
 */
final class InvoicesSection implements ClientPortalDownloadSection, ClientPortalRecordSection
{
    /** Everything a client may see of their own invoice — see the class docblock for what is missing. */
    public const COLUMNS = [
        'id', 'invoice_number', 'client_id', 'project_id', 'title', 'reference', 'currency',
        'issue_date', 'due_date', 'payment_terms_days', 'status',
        'tax_label', 'tax_rate', 'subtotal_amount', 'item_discount_amount', 'discount_amount',
        'total_discount_amount', 'taxable_amount', 'tax_amount', 'round_off_amount',
        'total_amount', 'paid_amount', 'balance_amount', 'payment_method_id',
        'notes', 'footer_note', 'bank_details', 'issued_at', 'sent_at', 'viewed_at', 'created_at',
    ];

    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'My Invoices';
    }

    public function icon(): string
    {
        return 'document-text';
    }

    public function module(): ?string
    {
        return 'invoices';
    }

    public function permission(): string
    {
        return 'client_portal.invoices';
    }

    public function sort(): int
    {
        return 40;
    }

    /**
     * What is still owed — the only count that means anything to a client. A total invoice count would
     * grow for ever and say nothing.
     */
    public function badgeCount(Client $client): ?int
    {
        return $this->query($client)->outstanding()->count();
    }

    public function paginate(Client $client, array $filters): LengthAwarePaginator
    {
        return $this->query($client)
            ->when(
                ($filters['status'] ?? null) === 'outstanding',
                fn (Builder $query) => $query->outstanding(),
            )
            ->when(
                ($filters['status'] ?? null) === 'paid',
                fn (Builder $query) => $query->where('status', InvoiceStatus::Paid->value),
            )
            ->when(
                ($filters['q'] ?? null) !== null,
                fn (Builder $query) => $query->where('invoice_number', 'like', '%'.trim((string) $filters['q']).'%'),
            )
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(per_page())
            ->withQueryString();
    }

    public function find(Client $client, User $user, int $id): ?Model
    {
        return $this->query($client)->whereKey($id)->first();
    }

    /**
     * The PDF is not built in this release — dompdf is not installed — so the download route answers a
     * plain 404 rather than streaming an empty file. The document itself is on the detail screen, and
     * the browser's own print is what produces paper today.
     */
    public function download(Client $client, User $user, int $id, string $variant): Response
    {
        $invoice = $this->find($client, $user, $id);

        abort_if($invoice === null, Response::HTTP_NOT_FOUND);
        abort_unless($variant === 'pdf', Response::HTTP_NOT_FOUND);

        abort(Response::HTTP_NOT_FOUND);

        return ResponseFactory::noContent();
    }

    public function view(): string
    {
        return 'client.invoices.index';
    }

    public function detailView(): string
    {
        return 'client.invoices.show';
    }

    /**
     * @return Builder<Invoice>
     */
    private function query(Client $client): Builder
    {
        return Invoice::query()
            ->select(self::COLUMNS)
            ->where('client_id', $client->getKey())
            // Never a draft: it carries no number and the business has not committed to it.
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->whereNotNull('invoice_number');
    }
}
