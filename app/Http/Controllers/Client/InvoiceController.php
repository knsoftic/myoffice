<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's invoices — `client.invoices.index`, `.show`, `.pdf` (phase-05 §7, §8.10, §9.2, test 71), filled by
 * Phase 13's `invoices` section.
 *
 * `invoices.client_id = ClientContext::clientId() AND status <> draft`; a cancelled invoice is shown as cancelled,
 * never hidden. The PDF needs `client_portal.download` as well and is streamed by the section from the private disk.
 */
final class InvoiceController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.invoices');

        return $this->sectionList($request, 'invoices');
    }

    public function show(Request $request, string $invoice): View
    {
        $this->authorize('client_portal.invoices');

        return $this->sectionDetail($request, 'invoices', $invoice);
    }

    public function pdf(Request $request, string $invoice): Response
    {
        $this->authorize('client_portal.download');

        return $this->sectionDownload($request, 'invoices', $invoice, 'pdf');
    }
}
