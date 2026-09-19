<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataObjects\Crm\ClientFilters;
use App\DataObjects\Crm\ClientFinancialSummary;
use App\Enums\ClientDocumentCategory;
use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\InquirySource;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\AssignAccountManagerRequest;
use App\Http\Requests\Crm\ChangeClientStatusRequest;
use App\Http\Requests\Crm\CrmListRequest;
use App\Http\Requests\Crm\DeleteRecordRequest;
use App\Http\Requests\Crm\DisableClientPortalRequest;
use App\Http\Requests\Crm\DownloadCrmExportRequest;
use App\Http\Requests\Crm\EnableClientPortalRequest;
use App\Http\Requests\Crm\StoreClientDocumentRequest;
use App\Http\Requests\Crm\StoreClientRequest;
use App\Http\Requests\Crm\UpdateClientRequest;
use App\Models\Activity;
use App\Models\Crm\Client;
use App\Models\User;
use App\Services\Crm\ClientExporter;
use App\Services\Crm\ClientService;
use App\Services\Crm\CrmExportFiles;
use App\Support\Modules;
use App\Support\SettingsRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Clients — the client master, its tax details, account manager and portal access, `admin.clients.*`
 * (phase-05 §2.7, §6.7, §7, §8.7, §8.8, tests 56-64), `module:clients`.
 *
 * **Money is withheld, not hidden.** Invoiced / paid / outstanding figures exist on these screens only for
 * `clients.view_financial`: without it they are never computed, never passed to a view and never serialised, so
 * they are absent from the response body (test 58, resolutions §8 #6). With it they come from
 * `ClientService::financialSummary()`, which reports an explicit `unavailable` state while no later phase has bound
 * its read model — rendered "-", never a fabricated `0.00` (test 59).
 *
 * **Portal.** Enabling needs `clients.change_status` and `users.create` (the `managePortal` policy plus the request),
 * never takes or sends a password, and refuses a user already bound elsewhere. Disabling keeps the user and history.
 */
final class ClientController extends Controller
{
    use RespondsForCrm;

    private const SORTABLE = ['client_code', 'name', 'company_name', 'status', 'country', 'created_at'];

    /** Export columns that carry money — dropped for anyone without `clients.view_financial`. */
    private const FINANCIAL_COLUMNS = ['invoiced', 'paid', 'outstanding', 'overdue'];

    public function __construct(
        private readonly ClientService $clients,
    ) {}

    public function index(CrmListRequest $request): View
    {
        $this->authorize('clients.view_any');
        $this->authorize('viewAny', Client::class);

        $actor = $this->actor($request);
        $sort = $request->sortColumn(self::SORTABLE, 'company_name');
        $direction = $request->sortDirection('asc');
        $showFinancial = $actor->can('clients.view_financial');

        $clients = $this->filteredQuery($request, $actor)
            ->with(['accountManager:id,name', 'portalUser:id,name,email,last_login_at'])
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $data = [
            'clients' => $clients,
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $this->wantsTrashed($request, $actor),
            'statusOptions' => ClientStatus::options(),
            'accountManagerOptions' => $this->usersHolding('clients.view'),
            'countryOptions' => $this->countryOptions(),
            'portalOptions' => ['enabled' => 'Enabled', 'invited' => 'Invited', 'off' => 'Off'],
            'isEmptyModule' => ! Client::query()->exists(),
            // No Phase 5 read model counts projects; the column renders once Phase 6 binds one (D28).
            'projectsAvailable' => false,
            'projectCounts' => [],
            'showFinancial' => $showFinancial,
            'whatsappTemplate' => $this->whatsappTemplate(),
        ];

        if ($showFinancial) {
            // Computed — and present in the view data — only for `clients.view_financial` (test 58). A null is an
            // unavailable figure, rendered "-", never a fabricated 0.00 (test 59).
            $data['outstanding'] = $clients->getCollection()
                ->mapWithKeys(function (Client $client): array {
                    $summary = $this->summary($client);

                    return [(int) $client->getKey() => $summary->isAvailable() ? $summary->outstanding : null];
                })
                ->all();
        }

        return view('admin.clients.index', $data);
    }

    public function create(Request $request): View
    {
        $this->authorize('clients.create');
        $this->authorize('create', Client::class);

        $actor = $this->actor($request);

        return view('admin.clients.create', array_merge($this->formData(), [
            'client' => new Client([
                'client_type' => ClientType::Company->value,
                'billing_same_as_address' => true,
            ]),
            'accountManagerOptions' => $actor->can('clients.assign') ? $this->usersHolding('clients.view') : [],
        ]));
    }

    public function store(StoreClientRequest $request): Response
    {
        $this->authorize('clients.create');
        $this->authorize('create', Client::class);

        return $this->attempt($request, function () use ($request): Response {
            $client = $this->clients->create($request->toData());

            return $this->done(
                $request,
                sprintf('Client %s (%s) was added.', $client->display_name, $client->client_code),
                redirect()->route('admin.clients.show', $client),
                ['id' => (int) $client->getKey(), 'client_code' => $client->client_code],
            );
        });
    }

    /**
     * §8.8 — overview, tax details, contacts, documents, projects (capability-gated), financials (withheld without
     * `clients.view_financial`), portal access and the activity trail.
     */
    public function show(Request $request, Client $client): View
    {
        $this->authorize('view', $client);

        $actor = $this->actor($request);
        $canViewFinancial = $actor->can('viewFinancial', $client);
        $canSeeDocuments = Modules::enabled('client_documents') && $actor->can('client_documents.view_any');

        $client->load([
            'accountManager:id,name,email',
            'portalUser:id,name,email,status,last_login_at',
            'originLead:id,lead_no,name,status',
            'creator:id,name',
        ]);

        $data = [
            'client' => $client,
            'contacts' => $client->contacts()->with('user:id,name,email,status,last_login_at')->orderByDesc('is_primary')->orderBy('name')->get(),
            'documents' => $canSeeDocuments
                ? $client->documents()->with(['creator:id,name', 'sharedBy:id,name'])->latest('id')->limit(10)->get()
                : new EloquentCollection,
            'documentCount' => $canSeeDocuments ? $client->documents()->count() : null,
            'canSeeDocuments' => $canSeeDocuments,
            'conversions' => $client->conversions()->with(['lead:id,lead_no,name', 'convertedBy:id,name'])->orderByDesc('id')->get(),
            'activities' => $actor->can('clients.view_logs') ? $this->activityTrail($client) : new EloquentCollection,
            'canViewActivity' => $actor->can('clients.view_logs'),
            'canViewFinancial' => $canViewFinancial,
            'statusOptions' => ClientStatus::options(),
            'accountManagerOptions' => $actor->can('assign', $client) ? $this->usersHolding('clients.view') : [],
            'documentCategoryOptions' => ClientDocumentCategory::options(),
            'documentUpload' => $this->documentUploadHints(),
            'projectsAvailable' => Modules::enabled('projects') && class_exists('App\\Models\\Project\\Project'),
            'whatsappTemplate' => $this->whatsappTemplate(),
        ];

        if ($canViewFinancial) {
            $data['financialSummary'] = $this->summary($client);
        }

        return view('admin.clients.show', $data);
    }

    public function edit(Client $client): View
    {
        $this->authorize('update', $client);

        return view('admin.clients.edit', array_merge($this->formData(), [
            'client' => $client,
        ]));
    }

    public function update(UpdateClientRequest $request, Client $client): Response
    {
        $this->authorize('update', $client);

        return $this->attempt($request, function () use ($request, $client): Response {
            $client = $this->clients->update($client, $request->toData());

            return $this->done(
                $request,
                sprintf('Client %s was updated.', $client->client_code),
                redirect()->route('admin.clients.show', $client),
                ['id' => (int) $client->getKey()],
            );
        });
    }

    /**
     * Soft delete with a reason. Refused while a project, invoice or payment references the client; the portal is
     * disabled first; the `client_code` is never reused.
     */
    public function destroy(DeleteRecordRequest $request, Client $client): Response
    {
        $this->authorize('delete', $client);

        return $this->attempt($request, function () use ($request, $client): Response {
            $this->clients->delete($client, (string) $request->reason());

            return $this->done($request, sprintf('Client %s was deleted.', $client->client_code), redirect()->route('admin.clients.index'));
        });
    }

    public function restore(Request $request, Client $client): Response
    {
        $this->authorize('clients.restore');
        $this->authorize('restore', $client);

        return $this->attempt($request, function () use ($request, $client): Response {
            $this->clients->restore($client);

            return $this->done($request, sprintf('Client %s was restored.', $client->client_code), redirect()->route('admin.clients.show', $client));
        });
    }

    public function print(Request $request, Client $client): View
    {
        $this->authorize('clients.print');
        $this->authorize('print', $client);

        $actor = $this->actor($request);

        $data = [
            'client' => $client->load(['accountManager:id,name', 'contacts']),
            'printedBy' => $actor,
            'canViewFinancial' => $actor->can('viewFinancial', $client),
        ];

        if ($data['canViewFinancial']) {
            $data['financialSummary'] = $this->summary($client);
        }

        return view('admin.clients.print', $data);
    }

    /**
     * CSV of the filtered clients (§6.10 `ClientExporter`); money columns only for `clients.view_financial`.
     */
    public function export(CrmListRequest $request): Response
    {
        $this->authorize('clients.export');
        $this->authorize('export', Client::class);

        $actor = $this->actor($request);
        $columns = $request->filterList('columns');

        if (! $actor->can('clients.view_financial')) {
            $columns = array_values(array_diff($columns, self::FINANCIAL_COLUMNS));
        }

        $response = app(ClientExporter::class)->stream($this->clientFilters($request, $actor), $columns);

        if ($response instanceof Response) {
            return $response;
        }

        return $this->done($request, 'The export is larger than can be downloaded at once. It is being prepared and you will be notified when it is ready.', null, ['queued' => true], 'info');
    }

    /**
     * The file of a queued export, from the notification's link. `CrmExportFiles` refuses anyone but its requester.
     */
    public function exportDownload(DownloadCrmExportRequest $request): Response
    {
        $this->authorize('clients.export');

        return app(CrmExportFiles::class)->download(ClientExporter::TYPE, $request->token(), $this->actor($request));
    }

    public function status(ChangeClientStatusRequest $request, Client $client): Response
    {
        $this->authorize('changeStatus', $client);

        $status = $request->newStatus();

        return $this->attempt($request, function () use ($request, $client, $status): Response {
            $this->clients->changeStatus($client, $status, $request->reason());

            return $this->done(
                $request,
                sprintf('Client %s is now %s.', $client->client_code, mb_strtolower($status->label())),
                null,
                ['id' => (int) $client->getKey(), 'status' => $status->value, 'status_label' => $status->label()],
                $status->canUsePortal() ? 'success' : 'warning',
            );
        });
    }

    public function accountManager(AssignAccountManagerRequest $request, Client $client): Response
    {
        $this->authorize('assign', $client);

        $manager = $request->manager();

        return $this->attempt($request, function () use ($request, $client, $manager): Response {
            $this->clients->assignAccountManager($client, $manager, $request->reason());

            return $this->done(
                $request,
                $manager instanceof User ? sprintf('%s now manages %s.', $manager->name, $client->client_code) : sprintf('%s has no account manager now.', $client->client_code),
                null,
                ['id' => (int) $client->getKey(), 'account_manager_id' => $manager?->getKey(), 'account_manager_name' => $manager?->name],
            );
        });
    }

    /**
     * The financial block on its own — `can:viewFinancial,client`. JSON for the tab's lazy load, a page otherwise.
     */
    public function financials(Request $request, Client $client): View|JsonResponse
    {
        $this->authorize('viewFinancial', $client);

        $summary = $this->summary($client);

        if ($request->expectsJson()) {
            return new JsonResponse(['client_id' => (int) $client->getKey(), 'summary' => $summary->toArray()]);
        }

        return view('admin.clients.financials', [
            'client' => $client,
            'financialSummary' => $summary,
        ]);
    }

    public function enablePortal(EnableClientPortalRequest $request, Client $client): Response
    {
        $this->authorize('managePortal', $client);
        $this->authorize('clients.change_status');
        $this->authorize('users.create');

        return $this->attempt($request, function () use ($request, $client): Response {
            $user = $this->clients->enablePortal($client, $request->toData());

            return $this->done(
                $request,
                sprintf('Portal access is on for %s. An invitation to set a password was sent to %s.', $client->display_name, $user->email),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'portal']),
                ['id' => (int) $client->getKey(), 'user_id' => (int) $user->getKey()],
            );
        });
    }

    public function disablePortal(DisableClientPortalRequest $request, Client $client): Response
    {
        $this->authorize('managePortal', $client);

        return $this->attempt($request, function () use ($request, $client): Response {
            $this->clients->disablePortal($client, (string) $request->reason());

            return $this->done(
                $request,
                sprintf('Portal access is off for %s. Their login and history are kept.', $client->display_name),
                redirect()->route('admin.clients.show', ['client' => $client, 'tab' => 'portal']),
                ['id' => (int) $client->getKey()],
                'warning',
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<Client>
     */
    private function filteredQuery(CrmListRequest $request, User $actor): Builder
    {
        $query = $this->clientFilters($request, $actor)->apply(Client::query());

        if ($request->filterString('portal') === 'invited') {
            $query->whereNotNull('portal_invited_at');
        }

        if ($request->filterString('account_manager') === 'none') {
            $query->whereNull('account_manager_id');
        }

        return $query;
    }

    private function clientFilters(CrmListRequest $request, User $actor): ClientFilters
    {
        return ClientFilters::fromArray($this->clientFilterInput($request, $actor));
    }

    /**
     * The list filters in the DTO's keys — also what a queued export stores to rebuild them.
     *
     * @return array<string, mixed>
     */
    private function clientFilterInput(CrmListRequest $request, User $actor): array
    {
        $manager = $request->filterString('account_manager');
        $portal = $request->filterString('portal');

        return [
            'search' => $request->searchTerm(),
            'status' => array_map(static fn (ClientStatus $status): string => $status->value, $request->filterEnums('status', ClientStatus::class)),
            'account_manager_id' => $manager !== null && ctype_digit($manager) ? $manager : null,
            'country' => $request->filterString('country'),
            'portal_enabled' => match ($portal) {
                'enabled' => '1',
                'off' => '0',
                default => null,
            },
            'created_preset' => $request->filterString('range'),
            'created_from' => $request->filterString('from'),
            'created_to' => $request->filterString('to'),
            'trashed' => $this->wantsTrashed($request, $actor) ? '1' : null,
        ];
    }

    /**
     * The distinct countries on file, value => label.
     *
     * @return array<string, string>
     */
    private function countryOptions(): array
    {
        return Client::query()
            ->whereNotNull('country')
            ->where('country', '<>', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->mapWithKeys(static fn (mixed $country): array => [(string) $country => (string) $country])
            ->all();
    }

    private function wantsTrashed(CrmListRequest $request, User $actor): bool
    {
        return $request->filterBool('trashed') === true && $actor->can('clients.restore');
    }

    private function summary(Client $client): ClientFinancialSummary
    {
        try {
            return $this->clients->financialSummary($client);
        } catch (Throwable $exception) {
            report($exception);

            return ClientFinancialSummary::unavailable();
        }
    }

    /**
     * The `activity_log` entries whose subject is this client, newest first.
     *
     * @return EloquentCollection<int, Activity>
     */
    private function activityTrail(Client $client): EloquentCollection
    {
        return Activity::query()
            ->where('subject_type', $client->getMorphClass())
            ->where('subject_id', $client->getKey())
            ->with('causer')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * The client form's choices.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'clientTypeOptions' => ClientType::options(),
            'sourceOptions' => InquirySource::options(),
            // The placeholders a blank commercial term falls back to (the client row stores null, §2.7).
            'defaultCurrency' => $this->settingString('localization.currency'),
            'defaultPaymentTermsDays' => is_numeric($terms = $this->settingValue('finance.payment_terms_days')) ? (int) $terms : null,
            'defaultTaxRate' => $this->settingString('finance.default_tax_rate'),
        ];
    }

    private function settingValue(string $key): mixed
    {
        try {
            return settings_repo()->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function settingString(string $key): ?string
    {
        $value = $this->settingValue($key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * The upload control's courtesy hint (the Form Request is authoritative).
     *
     * @return array{extensions: list<string>, max_kb: int, visible_default: bool}
     */
    private function documentUploadHints(): array
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
