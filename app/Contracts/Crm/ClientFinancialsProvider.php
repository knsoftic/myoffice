<?php

declare(strict_types=1);

namespace App\Contracts\Crm;

use App\Models\Crm\Client;

/**
 * A later phase's read model answering money questions about a client (phase-05 §6.7 `financialSummary()`, D28).
 *
 * `ClientService::financialSummary()` never sums an invoice or a payment itself (INV-26). Phase 13 contributes
 * the invoice figures and the spine the received payments by binding an implementation and tagging it
 * `crm.client_financials` in its own service provider:
 *
 *   $this->app->tag([InvoiceClientFinancials::class], ClientFinancialsProvider::TAG);
 *
 * With nothing tagged the summary is an explicit `unavailable` snapshot, rendered as "-", never `0.00`.
 */
interface ClientFinancialsProvider
{
    public const TAG = 'crm.client_financials';

    public function isAvailable(): bool;

    /**
     * The figures this provider owns, each a decimal(15,2) string. Keys it does not own are omitted.
     *
     * @return array{invoiced?: string, paid?: string, outstanding?: string, overdue?: string}
     */
    public function figuresFor(Client $client): array;
}
