<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ClientDocumentCategory;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ChangeDocumentVisibilityRequest;
use App\Http\Requests\Crm\CrmListRequest;
use App\Http\Requests\Crm\StoreClientDocumentRequest;
use App\Http\Requests\Crm\UpdateClientDocumentRequest;
use App\Models\Crm\Client;
use App\Models\Crm\ClientDocument;
use App\Services\Crm\ClientDocumentService;
use App\Support\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Private client documents — `admin.clients.documents.*`, `admin.client-documents.download` (phase-05 §2.9, §6.8,
 * §8.9, tests 65-70), `module:client_documents`.
 *
 * **Private by default (D21, [D-P5-10]).** Files live on the private `local` disk under
 * `clients/{id}/documents/` with a hashed name; the only way out is `download()`, which re-runs the permission chain
 * (`client_documents.download` + `ClientDocumentPolicy::download`) and streams through `ClientDocumentService`, which
 * logs every download. `visible_to_client` is the portal gate only — it has no effect on staff.
 *
 * The list and the download are independent permissions (test 70): `client_documents.view_any` renders the list,
 * `client_documents.download` streams a file. `{document}` must belong to `{client}`, else 404.
 */
final class ClientDocumentController extends Controller
{
    use RespondsForCrm;

    private const SORTABLE = ['title', 'category', 'size_bytes', 'expires_at', 'created_at'];

    public function __construct(
        private readonly ClientDocumentService $documents,
    ) {}

    public function index(CrmListRequest $request, Client $client): View|JsonResponse
    {
        $this->authorize('client_documents.view_any');
        $this->authorize('viewAny', ClientDocument::class);

        $actor = $this->actor($request);
        $category = $request->filterEnum('category', ClientDocumentCategory::class);
        $visible = $request->filterBool('visible');
        $expiring = $request->filterString('expiring');
        $search = $request->searchTerm();
        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');
        $today = CarbonImmutable::today();

        $documents = ClientDocument::query()
            ->forClient($client)
            ->with(['creator:id,name', 'sharedBy:id,name'])
            ->when($category instanceof ClientDocumentCategory, static fn ($query) => $query->where('category', $category?->value))
            ->when($visible !== null, static fn ($query) => $query->where('visible_to_client', $visible))
            ->when($expiring === 'past', static fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<', $today->toDateString()))
            ->when($expiring === 'soon', static fn ($query) => $query->expiringWithin(ClientDocument::EXPIRY_WARNING_DAYS, $today))
            ->when($search !== null, fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('title', 'like', $this->like((string) $search))
                    ->orWhere('original_name', 'like', $this->like((string) $search))
                    ->orWhere('description', 'like', $this->like((string) $search));
            }))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        if ($request->expectsJson()) {
            return new JsonResponse([
                'data' => $documents->getCollection()->map(static fn (ClientDocument $document): array => [
                    'id' => (int) $document->getKey(),
                    'title' => $document->title,
                    'category' => $document->category?->value,
                    'category_label' => $document->category?->label(),
                    'size_bytes' => (int) $document->size_bytes,
                    'extension' => $document->extension,
                    'visible_to_client' => (bool) $document->visible_to_client,
                    'expires_at' => $document->expires_at?->toDateString(),
                    'is_expired' => $document->isExpired(),
                ])->values()->all(),
                'meta' => ['current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(), 'total' => $documents->total()],
            ]);
        }

        return view('admin.clients.documents.index', [
            'client' => $client,
            'documents' => $documents,
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'categoryOptions' => ClientDocumentCategory::options(),
            'expiryWarningDays' => ClientDocument::EXPIRY_WARNING_DAYS,
            'upload' => $this->uploadHints(),
            'can' => [
                'upload' => $actor->can('client_documents.upload'),
                'download' => $actor->can('client_documents.download'),
                'edit' => $actor->can('client_documents.edit'),
                'share' => $actor->can('client_documents.change_status'),
                'delete' => $actor->can('client_documents.delete'),
            ],
        ]);
    }

    public function store(StoreClientDocumentRequest $request, Client $client): Response
    {
        $this->authorize('client_documents.upload');
        $this->authorize('upload', [ClientDocument::class, $client]);

        return $this->attempt($request, function () use ($request, $client): Response {
            $document = $this->documents->upload($client, $request->document(), $request->toData());

            return $this->done(
                $request,
                (bool) $document->visible_to_client
                    ? sprintf('%s was uploaded and is visible to the client.', $document->title)
                    : sprintf('%s was uploaded. The client cannot see it.', $document->title),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'documents']),
                ['id' => (int) $document->getKey(), 'visible_to_client' => (bool) $document->visible_to_client],
            );
        });
    }

    public function update(UpdateClientDocumentRequest $request, Client $client, ClientDocument $document): Response
    {
        $this->assertBelongs($client, $document);
        $this->authorize('update', $document);

        return $this->attempt($request, function () use ($request, $client, $document): Response {
            $document = $this->documents->update($document, $request->toData());

            return $this->done(
                $request,
                sprintf('%s was updated.', $document->title),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'documents']),
                ['id' => (int) $document->getKey()],
            );
        });
    }

    /**
     * Share with, or withdraw from, the client portal. Sharing notifies the client's portal contacts immediately.
     */
    public function visibility(ChangeDocumentVisibilityRequest $request, Client $client, ClientDocument $document): Response
    {
        $this->assertBelongs($client, $document);
        $this->authorize('changeVisibility', $document);

        $visible = $request->visible();

        return $this->attempt($request, function () use ($request, $client, $document, $visible): Response {
            $this->documents->setClientVisibility($document, $visible, $request->reason());

            return $this->done(
                $request,
                $visible
                    ? sprintf('%s is now visible to the client, and their portal contacts were notified.', $document->title)
                    : sprintf('%s is no longer visible to the client.', $document->title),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'documents']),
                ['id' => (int) $document->getKey(), 'visible_to_client' => $visible],
            );
        });
    }

    /**
     * Stream one document from the private disk (`Content-Disposition: attachment`, `nosniff`, the stored MIME type);
     * the service writes the download to the activity log.
     */
    public function download(Request $request, ClientDocument $document): Response
    {
        $this->authorize('client_documents.download');
        $this->authorize('download', $document);

        return $this->documents->download($document, $this->actor($request));
    }

    public function destroy(Request $request, Client $client, ClientDocument $document): Response
    {
        $this->assertBelongs($client, $document);
        $this->authorize('delete', $document);

        return $this->attempt($request, function () use ($request, $client, $document): Response {
            $this->documents->delete($document);

            return $this->done(
                $request,
                sprintf('%s was deleted.', $document->title),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'documents']),
                ['id' => (int) $document->getKey()],
            );
        });
    }

    private function assertBelongs(Client $client, ClientDocument $document): void
    {
        $this->abortUnlessVisible($document->belongsToClient($client));
    }

    /**
     * The upload control's hint — the same types and size the Form Request enforces.
     *
     * @return array{extensions: list<string>, max_kb: int, visible_default: bool}
     */
    private function uploadHints(): array
    {
        try {
            $allowed = settings_repo()->get('security.allowed_file_types');
            $megabytes = settings_repo()->get('security.max_upload_mb');
        } catch (Throwable) {
            $allowed = null;
            $megabytes = null;
        }

        return [
            'extensions' => SettingsRegistry::uploadExtensions(StoreClientDocumentRequest::DOCUMENT_TYPES, $allowed),
            'max_kb' => SettingsRegistry::uploadKilobytes(StoreClientDocumentRequest::MAX_KILOBYTES, $megabytes),
            'visible_default' => $this->crmBool('client_visible_documents_default', false),
        ];
    }
}
