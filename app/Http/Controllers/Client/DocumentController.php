<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use App\Models\Crm\ClientDocument;
use App\Services\Crm\ClientDocumentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contracts and papers shared with the client — `client.documents.index`, `client.documents.download`
 * (phase-05 §2.9, §6.8, §8.10, §9.2, tests 67-69), Phase 5's own `documents` section, `module:client_documents`.
 *
 * The rule is `client_documents.client_id = ClientContext::clientId() AND visible_to_client = 1 AND deleted_at IS
 * NULL`. A document of another client, or one of this client's that is not shared, is a **404** and never appears in
 * the list. The download additionally needs `client_portal.download` (listing and downloading are separate grants,
 * §4.3), is authorised by `ClientDocumentPolicy::downloadAsClient`, and is streamed and logged by the service.
 */
final class DocumentController extends Controller
{
    use ServesClientPortal;

    public function __construct(
        private readonly ClientDocumentService $documents,
    ) {}

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.documents');

        return $this->sectionList($request, 'documents', [], [
            'canDownload' => $this->portalUser($request)->can('client_portal.download'),
        ]);
    }

    public function download(Request $request, string $document): Response
    {
        $this->authorize('client_portal.download');
        $this->section($request, 'documents');

        $record = ClientDocument::query()
            ->sharedWithClient($this->client())
            ->whereKey($this->routeId($document))
            ->first();

        abort_unless($record instanceof ClientDocument, Response::HTTP_NOT_FOUND);

        $this->authorize('downloadAsClient', $record);

        return $this->documents->download($record, $this->portalUser($request));
    }
}
