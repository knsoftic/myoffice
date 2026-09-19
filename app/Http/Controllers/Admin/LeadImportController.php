<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\InquirySource;
use App\Enums\LeadImportDuplicateStrategy;
use App\Enums\LeadImportRowStatus;
use App\Enums\LeadImportStatus;
use App\Enums\LeadStatus;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CancelLeadImportRequest;
use App\Http\Requests\Crm\CrmListRequest;
use App\Http\Requests\Crm\StoreLeadImportRequest;
use App\Http\Requests\Crm\UpdateLeadImportMappingRequest;
use App\Models\Crm\LeadImport;
use App\Models\User;
use App\Services\Crm\LeadImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The four-step CSV import wizard — `admin.leads.import.*` (phase-05 §2.5, §2.6, §6.6, §8.6, tests 37-42),
 * `module:leads`, `can:leads.import` on every route.
 *
 * **Visibility (§9.1).** An import belongs to the person who staged it; `leads.view_any` sees every import. Anyone
 * else asking for an import id gets a **404** (`LeadImport::isVisibleTo()`), never a 403 that would confirm it exists.
 *
 * **Files (D21).** The staged CSV and the error report live on the private `local` disk; `LeadImportService` streams
 * the error report after this controller has re-run the permission chain. Neither file has a public URL.
 *
 * **Progress.** `show()` answers the wizard's JSON poll with the same `$progress` array the page renders. Cancelling
 * keeps every lead already created and says how many.
 */
final class LeadImportController extends Controller
{
    use RespondsForCrm;

    private const SORTABLE = ['created_at', 'original_filename', 'status'];

    public function __construct(
        private readonly LeadImportService $imports,
    ) {}

    public function index(CrmListRequest $request): View
    {
        $this->authorize('leads.import');
        $this->authorize('viewAny', LeadImport::class);

        $actor = $this->actor($request);
        $status = $request->filterEnum('status', LeadImportStatus::class);
        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');

        $imports = LeadImport::query()
            ->visibleTo($actor)
            ->with('creator:id,name')
            ->when($status instanceof LeadImportStatus, static fn ($query) => $query->where('status', $status?->value))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin.leads.import.index', [
            'imports' => $imports,
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'statusOptions' => LeadImportStatus::options(),
            'maxRows' => $this->crmInt('import_max_rows', 5000, 1),
            'maxUploadMb' => $this->maxUploadMb(),
            'fileRetentionDays' => $this->crmInt('import_file_retention_days', 30),
        ]);
    }

    /**
     * The sample CSV with the exact expected headers.
     */
    public function template(): Response
    {
        $this->authorize('leads.import');
        $this->authorize('create', LeadImport::class);

        return $this->imports->template();
    }

    /**
     * Step 1 — stage the file (sniffed, stored privately, counted), then continue to the mapping step.
     */
    public function store(StoreLeadImportRequest $request): Response
    {
        $this->authorize('leads.import');
        $this->authorize('create', LeadImport::class);

        return $this->attempt($request, function () use ($request): Response {
            $import = $this->imports->stage($request->csv(), $request->toOptions());

            return $this->done(
                $request,
                sprintf('%s was uploaded. Map its columns to continue.', $import->original_filename),
                redirect()->route('admin.leads.import.show', ['import' => $import, 'step' => 2]),
                ['id' => (int) $import->getKey(), 'url' => route('admin.leads.import.show', $import)],
            );
        });
    }

    /**
     * Steps 2-4 for one import, and the progress poll.
     */
    public function show(Request $request, LeadImport $import): View|JsonResponse
    {
        $this->authorize('leads.import');
        $actor = $this->actor($request);
        $this->assertVisible($actor, $import);
        $this->authorize('view', $import);

        $progress = $this->progress($import);

        if ($request->expectsJson()) {
            return new JsonResponse($progress);
        }

        $status = $this->statusOf($import);
        $headers = $this->safely(fn (): array => $this->imports->headers($import), []);
        $previous = $this->safely(fn (): ?LeadImport => $this->imports->previousImportOf($import), null);

        return view('admin.leads.import.show', [
            'import' => $import->loadMissing('creator:id,name'),
            'step' => $this->step($request, $status),
            'headers' => $headers,
            'columnMap' => $this->positionalMap($import, $headers),
            'targetFields' => $this->targetFields(),
            'previewRows' => $this->positionalPreview($import, $headers),
            'previousImport' => $previous instanceof LeadImport && $previous->isVisibleTo($actor) ? $previous : null,
            'sourceOptions' => InquirySource::options(),
            'defaultStatusOptions' => array_diff_key($this->statusOptions(), [LeadStatus::Won->value => true, LeadStatus::Lost->value => true]),
            'assigneeOptions' => $this->usersHolding('leads.view'),
            'strategyOptions' => LeadImportDuplicateStrategy::options(),
            'validation' => $this->validationCounts($import, $status),
            'rowErrors' => in_array($status, [LeadImportStatus::Pending, LeadImportStatus::Mapping], true)
                ? null
                : $import->rows()
                    ->whereIn('status', [LeadImportRowStatus::SkippedInvalid->value, LeadImportRowStatus::Failed->value])
                    ->orderBy('row_number')
                    ->paginate(50, ['*'], 'errors_page')
                    ->withQueryString(),
            'progress' => $progress,
        ]);
    }

    /**
     * Steps 2-3 — the column map and the options (default source / assignee / status, duplicate strategy, stop after
     * N errors).
     */
    public function mapping(UpdateLeadImportMappingRequest $request, LeadImport $import): Response
    {
        $this->authorize('leads.import');
        $this->assertVisible($this->actor($request), $import);
        $this->authorize('view', $import);

        return $this->attempt($request, function () use ($request, $import): Response {
            $this->imports->updateMapping($import, $request->toOptions());

            return $this->done(
                $request,
                'The column mapping was saved.',
                redirect()->route('admin.leads.import.show', ['import' => $import, 'step' => 4]),
                ['id' => (int) $import->getKey()],
            );
        });
    }

    /**
     * Step 4a — the dry run: one `lead_import_rows` row per line with its errors, no lead created.
     */
    public function validateRows(Request $request, LeadImport $import): Response
    {
        $this->authorize('leads.import');
        $this->assertVisible($this->actor($request), $import);
        $this->authorize('run', $import);

        return $this->attempt($request, function () use ($request, $import): Response {
            $preview = $this->imports->validateRows($import);

            return $this->done(
                $request,
                sprintf('%d rows checked: %d valid, %d invalid, %d duplicates.', $preview->totalRows, $preview->validRows, $preview->invalidRows, $preview->duplicateRows),
                redirect()->route('admin.leads.import.show', ['import' => $import, 'step' => 4]),
                [
                    'id' => (int) $import->getKey(),
                    'total_rows' => $preview->totalRows,
                    'valid_rows' => $preview->validRows,
                    'invalid_rows' => $preview->invalidRows,
                    'duplicate_rows' => $preview->duplicateRows,
                    'stopped_early' => $preview->stoppedEarly,
                    'can_run' => $preview->canRun(),
                ],
                $preview->invalidRows > 0 ? 'warning' : 'success',
            );
        });
    }

    /**
     * Step 4b — run: chunked jobs, one transaction per row.
     */
    public function run(Request $request, LeadImport $import): Response
    {
        $this->authorize('leads.import');
        $this->assertVisible($this->actor($request), $import);
        $this->authorize('run', $import);

        return $this->attempt($request, function () use ($request, $import): Response {
            $this->imports->run($import);

            return $this->done(
                $request,
                'The import has started. This page shows its progress.',
                redirect()->route('admin.leads.import.show', ['import' => $import, 'step' => 4]),
                ['id' => (int) $import->getKey()],
            );
        });
    }

    /**
     * Stop further chunks. Leads already created are kept — deleting real data to undo an import is worse than an
     * honest partial result — and the message says how many.
     */
    public function cancel(CancelLeadImportRequest $request, LeadImport $import): Response
    {
        $this->authorize('leads.import');
        $this->assertVisible($this->actor($request), $import);
        $this->authorize('cancel', $import);

        return $this->attempt($request, function () use ($request, $import): Response {
            $kept = $this->imports->cancel($import, $request->reasonText());

            return $this->done(
                $request,
                sprintf('The import was cancelled. The %d %s already created %s kept.', $kept, Str::plural('lead', $kept), $kept === 1 ? 'was' : 'were'),
                redirect()->route('admin.leads.import.show', ['import' => $import, 'step' => 4]),
                ['id' => (int) $import->getKey(), 'kept' => $kept],
                'warning',
            );
        });
    }

    /**
     * The CSV of failed and invalid rows, streamed from the private disk by the service.
     */
    public function errors(Request $request, LeadImport $import): Response
    {
        $this->authorize('leads.import');
        $this->assertVisible($this->actor($request), $import);
        $this->authorize('downloadErrors', $import);

        return $this->imports->errorReport($import);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assertVisible(User $actor, LeadImport $import): void
    {
        $this->abortUnlessVisible($import->isVisibleTo($actor));
    }

    private function statusOf(LeadImport $import): LeadImportStatus
    {
        $status = $import->status;

        return $status instanceof LeadImportStatus ? $status : (LeadImportStatus::tryFrom((string) $status) ?? LeadImportStatus::Pending);
    }

    /**
     * The step to open: `?step=` clamped to 2-4, defaulting to what the status calls for (2 map, 3 options, 4 validate
     * and run).
     */
    private function step(Request $request, LeadImportStatus $status): int
    {
        $asked = $request->query('step');
        $asked = is_string($asked) && ctype_digit($asked) ? (int) $asked : null;

        $default = match ($status) {
            LeadImportStatus::Pending => 2,
            LeadImportStatus::Mapping => 3,
            default => 4,
        };

        return $asked === null ? $default : max(2, min(4, $asked));
    }

    /**
     * Exactly what the JSON poll returns.
     *
     * @return array{status: string, status_label: string, total_rows: int, processed: int, created_count: int, updated_count: int, skipped_count: int, failed_count: int, percent: int, finished: bool, message: ?string}
     */
    private function progress(LeadImport $import): array
    {
        $status = $this->statusOf($import);
        $total = (int) $import->total_rows;
        $processed = $import->processedCount();

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'total_rows' => $total,
            'processed' => $processed,
            'created_count' => (int) $import->created_count,
            'updated_count' => (int) $import->updated_count,
            'skipped_count' => (int) $import->skipped_count,
            'failed_count' => (int) $import->failed_count,
            'percent' => $total > 0 ? min(100, intdiv($processed * 100, $total)) : ($status->isFinished() ? 100 : 0),
            'finished' => $status->isFinished(),
            'message' => is_string($import->failure_message) ? $import->failure_message : null,
        ];
    }

    /**
     * The dry run's counts, once the import has been validated (read from its `lead_import_rows`).
     *
     * @return array{total: int, valid: int, invalid: int, duplicates: int, would_create: int, would_update: int, would_skip: int}|null
     */
    private function validationCounts(LeadImport $import, LeadImportStatus $status): ?array
    {
        if (in_array($status, [LeadImportStatus::Pending, LeadImportStatus::Mapping, LeadImportStatus::Validating], true)) {
            return null;
        }

        $total = $import->rows()->count();
        $invalid = $import->rows()->whereNotNull('errors')->count();
        $duplicates = $import->rows()->whereNull('errors')->whereNotNull('duplicate_lead_id')->count();
        $strategy = $import->duplicate_strategy instanceof LeadImportDuplicateStrategy
            ? $import->duplicate_strategy
            : LeadImportDuplicateStrategy::tryFrom((string) $import->duplicate_strategy);
        $valid = $total - $invalid;

        return [
            'total' => $total,
            'valid' => $valid,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'would_create' => $strategy === LeadImportDuplicateStrategy::ImportAndFlag ? $valid : max(0, $valid - $duplicates),
            'would_update' => $strategy === LeadImportDuplicateStrategy::UpdateExisting ? $duplicates : 0,
            'would_skip' => $invalid + ($strategy === LeadImportDuplicateStrategy::Skip ? $duplicates : 0),
        ];
    }

    /**
     * The saved header-keyed map as `position => field`, the shape the mapping step renders.
     *
     * @param  list<string>  $headers
     * @return array<int, ?string>
     */
    private function positionalMap(LeadImport $import, array $headers): array
    {
        $saved = is_array($import->column_map) ? $import->column_map : [];
        $map = [];

        foreach ($headers as $position => $header) {
            $field = $saved[$header] ?? null;
            $map[$position] = is_string($field) && $field !== '' ? $field : null;
        }

        return $map;
    }

    /**
     * The first five rows as the wizard previews them: positional, like the header row.
     *
     * @param  list<string>  $headers
     * @return list<list<?string>>
     */
    private function positionalPreview(LeadImport $import, array $headers): array
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = $this->safely(fn (): array => $this->imports->previewRows($import, 5), []);

        return array_map(static function (array $row) use ($headers): array {
            $cells = [];

            foreach ($headers as $header) {
                $value = $row[$header] ?? null;
                $cells[] = is_scalar($value) ? (string) $value : null;
            }

            return $cells;
        }, $rows);
    }

    /**
     * The importable lead fields with their labels; `name` is the only required mapping.
     *
     * @return array<string, array{label: string, required: bool}>
     */
    private function targetFields(): array
    {
        $fields = [];

        foreach (UpdateLeadImportMappingRequest::targets() as $field) {
            $fields[$field] = [
                'label' => match ($field) {
                    'budget_amount' => 'Budget',
                    'country_code' => 'Country code',
                    'assigned_to_email' => 'Assigned to (email)',
                    'referral_code' => 'Referral code',
                    default => Str::headline($field),
                },
                'required' => $field === 'name',
            ];
        }

        return $fields;
    }

    /**
     * A read that must not take the wizard page down (an unreadable staged file shows an empty preview instead).
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            report($exception);

            return $fallback;
        }
    }

    private function maxUploadMb(): ?int
    {
        try {
            $megabytes = settings_repo()->get('security.max_upload_mb');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($megabytes) ? (int) $megabytes : null;
    }
}
