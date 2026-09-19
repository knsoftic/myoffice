<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\ContactCandidate;
use App\DataObjects\Crm\ImportOptions;
use App\DataObjects\Crm\ImportPreview;
use App\DataObjects\Crm\LeadData;
use App\Enums\InquirySource;
use App\Enums\LeadImportDuplicateStrategy;
use App\Enums\LeadImportRowStatus;
use App\Enums\LeadImportStatus;
use App\Enums\LeadStatus;
use App\Events\Crm\LeadImportCompleted;
use App\Jobs\Crm\BuildLeadImportErrorReport;
use App\Jobs\Crm\ProcessLeadImportChunk;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadImport;
use App\Models\Crm\LeadImportRow;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Support\CsvWriter;
use App\Support\Money;
use App\Support\SettingsRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use finfo;
use Generator;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * CSV lead import: stage → map → validate (dry run) → run in chunks (phase-05 §2.5, §2.6, §6.6, tests 37-42).
 *
 *   · **Stage** — the MIME is sniffed from the content with `finfo` (a `.csv` that is really PHP is refused), the
 *     delimiter and encoding are sniffed, a BOM is stripped, the file lands on the **private** `local` disk under
 *     `crm/imports/` with a hashed name, and a file above `crm.import_max_rows` is refused before anything is
 *     written (test 38). The same `file_hash` imported before is reported, not refused.
 *   · **Validate** — one `lead_import_rows` row per CSV line, each carrying its field-level errors and its
 *     duplicate resolution; no lead is created. Rows stay `pending` until the run gives them their final status,
 *     so every row moves `pending → final` exactly once.
 *   · **Run** — `ProcessLeadImportChunk` per 200 rows, chained, then `BuildLeadImportErrorReport`. **Each row is its
 *     own transaction**: it inserts its `lead_import_rows` line keyed by `uq_lir_row` (a retried chunk hits the
 *     1062 and moves on), locks it, and acts only while it is still `pending` — so a lost queue ack can never
 *     double-create a lead or double-count (test 39), and one bad row never rolls back a good one (test 40).
 *     Counters move with atomic increments. `update_existing` changes only the fields the CSV supplies and never
 *     `status`, `assigned_to` or `budget_amount` (test 41).
 *   · **Cancel** — stops further rows and keeps every lead already created; the answer states how many (test 42).
 */
final class LeadImportService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    public const DISK = 'local';

    public const DIRECTORY = 'crm/imports';

    public const CHUNK_SIZE = 200;

    /** A CSV of 5,000 rows is well under this; the security setting can only narrow it. */
    private const OWN_MAX_KILOBYTES = 20480;

    /** @var list<string> */
    private const CSV_MIME_TYPES = [
        'text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'text/comma-separated-values',
        'application/vnd.ms-excel', 'text/tab-separated-values',
    ];

    /**
     * Lead fields a CSV column may map to, with the header spellings suggested for each.
     *
     * @var array<string, list<string>>
     */
    public const TARGET_FIELDS = [
        'name' => ['name', 'full name', 'lead name', 'contact name', 'contact'],
        'company' => ['company', 'company name', 'organisation', 'organization', 'business'],
        'email' => ['email', 'e-mail', 'email address', 'mail'],
        'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'cell', 'contact number', 'telephone'],
        'whatsapp' => ['whatsapp', 'whatsapp number', 'wa'],
        'country' => ['country'],
        'country_code' => ['country code', 'country_code', 'iso'],
        'interested_service' => ['service', 'interested service', 'interest', 'requirement'],
        'budget_amount' => ['budget', 'budget amount', 'amount'],
        'source' => ['source', 'lead source', 'channel'],
        'source_detail' => ['source detail', 'campaign', 'utm campaign'],
        'notes' => ['notes', 'note', 'comments', 'remarks', 'message'],
        'referral_code' => ['referral code', 'ref', 'referral'],
        'status' => ['status', 'stage'],
        'assigned_to_email' => ['assigned to', 'owner', 'assignee', 'owner email', 'assigned to email'],
    ];

    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadDuplicateDetector $duplicates,
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * Validate, sniff and store an uploaded CSV; returns the import in `mapping` with a suggested column map.
     */
    public function stage(UploadedFile $file, ImportOptions $options): LeadImport
    {
        if (! $file->isValid()) {
            throw CrmRuleException::refuse('file', 'The file could not be uploaded. Try again.');
        }

        $originalName = $this->safeOriginalName((string) $file->getClientOriginalName());
        $this->assertCsvName($originalName);

        $size = (int) $file->getSize();
        $maxKilobytes = SettingsRegistry::uploadKilobytes(self::OWN_MAX_KILOBYTES, settings_repo()->get('security.max_upload_mb'));

        if ($size <= 0) {
            throw CrmRuleException::refuse('file', 'The file is empty.');
        }

        if ($size > $maxKilobytes * 1024) {
            throw CrmRuleException::refuse('file', sprintf('The file may be at most %d KB.', $maxKilobytes));
        }

        $path = (string) $file->getRealPath();
        $bytes = $path === '' ? false : file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            throw CrmRuleException::refuse('file', 'The file could not be read.');
        }

        $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));

        if (! in_array($mime, self::CSV_MIME_TYPES, true) || preg_match('/<\?(?:php|=)/i', substr($bytes, 0, 4096)) === 1) {
            throw CrmRuleException::refuse('file', 'The file is not a plain CSV file.');
        }

        $hash = hash('sha256', $bytes);
        [$content, $encoding] = $this->toUtf8($bytes, $options->encoding);
        $delimiter = $options->delimiter ?? $this->sniffDelimiter($content);

        $headers = null;
        $count = 0;

        foreach ($this->parse($content, $delimiter) as $index => $cells) {
            if ($index === 0) {
                $headers = $this->normaliseHeaders($cells);

                continue;
            }

            $count++;
        }

        if ($headers === null || array_filter($headers, static fn (string $h): bool => ! str_starts_with($h, 'column_')) === []) {
            throw CrmRuleException::refuse('file', 'The first line must be a header row naming the columns.');
        }

        if ($count === 0) {
            throw CrmRuleException::refuse('file', 'The file has a header row but no leads.');
        }

        $max = $this->crmInt('import_max_rows', 5000, 1);

        if ($count > $max) {
            throw CrmRuleException::refuse('file', sprintf('The file has %d rows; one import may hold at most %d.', $count, $max));
        }

        $storedPath = sprintf('%s/%s/%s.csv', self::DIRECTORY, CarbonImmutable::now()->format('Y'), Str::lower((string) Str::ulid()));

        if (! $this->storage->disk(self::DISK)->put($storedPath, $content)) {
            throw CrmRuleException::refuse('file', 'The file could not be saved. Try again.');
        }

        try {
            return DB::transaction(function () use ($options, $originalName, $storedPath, $hash, $delimiter, $encoding, $headers, $count): LeadImport {
                $import = new LeadImport;
                $import->forceFill([
                    'original_filename' => $originalName,
                    'stored_path' => $storedPath,
                    'file_hash' => $hash,
                    'delimiter' => $delimiter,
                    'encoding' => $encoding,
                    'column_map' => $options->columnMap !== null ? $this->validMap($options->columnMap, $headers) : $this->suggestMap($headers),
                    'defaults' => $options->defaults(),
                    'duplicate_strategy' => $options->duplicateStrategy ?? LeadImportDuplicateStrategy::from('import_and_flag'),
                    'status' => LeadImportStatus::from('mapping'),
                    'total_rows' => $count,
                ]);
                $import->save();

                return $import;
            });
        } catch (Throwable $exception) {
            $this->storage->disk(self::DISK)->delete($storedPath);

            throw $exception;
        }
    }

    /**
     * The most recent earlier import of the very same file, for the "imported before on …" warning.
     */
    public function previousImportOf(LeadImport $import): ?LeadImport
    {
        return LeadImport::query()
            ->where('file_hash', $import->getAttribute('file_hash'))
            ->whereKeyNot($import->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return list<string>
     */
    public function headers(LeadImport $import): array
    {
        foreach ($this->parse($this->content($import), (string) $import->getAttribute('delimiter')) as $cells) {
            return $this->normaliseHeaders($cells);
        }

        return [];
    }

    /**
     * The first rows as they will be read, header => value.
     *
     * @return list<array<string, string|null>>
     */
    public function previewRows(LeadImport $import, int $limit = 5): array
    {
        $out = [];

        foreach ($this->rows($import) as $raw) {
            $out[] = $raw;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Save the column map, defaults and duplicate strategy (wizard steps 2-3).
     */
    public function updateMapping(LeadImport $import, ImportOptions $options): LeadImport
    {
        return DB::transaction(function () use ($import, $options): LeadImport {
            $locked = $this->lock($import);
            $status = $this->statusOf($locked);

            if (! in_array($status->value, ['pending', 'mapping', 'validated'], true)) {
                throw CrmRuleException::refuse('column_map', 'This import has already started; its mapping can no longer change.');
            }

            if ($options->defaultStatus instanceof LeadStatus && $options->defaultStatus->isTerminal()) {
                throw CrmRuleException::refuse('default_status', 'Imported leads can start in an open stage only.');
            }

            if ($options->defaultAssignedTo !== null && ! User::query()->active()->whereKey($options->defaultAssignedTo)->exists()) {
                throw CrmRuleException::refuse('default_assigned_to', 'Choose an active user.');
            }

            $locked->forceFill([
                'column_map' => $this->validMap($options->columnMap ?? (array) $locked->getAttribute('column_map'), $this->headers($locked)),
                'defaults' => $options->defaults(),
                'duplicate_strategy' => $options->duplicateStrategy ?? $locked->getAttribute('duplicate_strategy'),
                'status' => LeadImportStatus::from('mapping'),
            ]);

            if ($options->delimiter !== null) {
                $locked->forceFill(['delimiter' => $options->delimiter]);
            }

            $locked->save();

            $import->setRawAttributes($locked->getAttributes(), true);

            return $import;
        });
    }

    /**
     * The dry run: one `lead_import_rows` line per CSV line with its errors and duplicate resolution. No lead.
     */
    public function validateRows(LeadImport $import): ImportPreview
    {
        $locked = DB::transaction(function () use ($import): LeadImport {
            $locked = $this->lock($import);

            if (! in_array($this->statusOf($locked)->value, ['mapping', 'validated'], true) || $locked->getAttribute('started_at') !== null) {
                throw CrmRuleException::refuse('import', 'Only an import that has not started can be validated.');
            }

            $this->validMap((array) $locked->getAttribute('column_map'), $this->headers($locked));

            $locked->forceFill(['status' => LeadImportStatus::from('validating')])->save();

            return $locked;
        });

        $stopAfter = $this->defaultsOf($locked)['stop_after_errors'] ?? null;
        $stopAfter = is_numeric($stopAfter) && (int) $stopAfter > 0 ? (int) $stopAfter : null;
        $strategy = $this->strategyOf($locked);
        $total = 0;
        $invalid = 0;
        $duplicates = 0;
        $stopped = false;

        try {
            foreach ($this->rows($locked) as $rowNumber => $raw) {
                $total++;
                $mapped = $this->mapRow($locked, $raw);
                $errors = $this->rowErrors($mapped);
                $match = null;

                if ($errors === []) {
                    $match = $this->leadMatch($mapped);
                } else {
                    $invalid++;
                }

                if ($match !== null) {
                    $duplicates++;
                }

                $this->recordPreviewRow($locked, (int) $rowNumber, $raw, $errors, $match, $strategy);

                if ($stopAfter !== null && $invalid >= $stopAfter) {
                    $stopped = true;

                    break;
                }
            }
        } catch (Throwable $exception) {
            // A dry run that dies leaves the import mappable again rather than stuck in `validating`.
            $locked->forceFill(['status' => LeadImportStatus::from('mapping')])->save();

            throw $exception;
        }

        $locked->forceFill([
            'status' => LeadImportStatus::from('validated'),
            'total_rows' => max($total, (int) $locked->getAttribute('total_rows')),
        ])->save();

        $import->setRawAttributes($locked->getAttributes(), true);

        $errorRows = LeadImportRow::query()
            ->where('lead_import_id', $locked->getKey())
            ->whereNotNull('errors')
            ->orderBy('row_number')
            ->limit(50)
            ->get();

        return new ImportPreview(
            import: $locked,
            totalRows: $total,
            validRows: $total - $invalid,
            invalidRows: $invalid,
            duplicateRows: $duplicates,
            errorRows: $errorRows,
            stoppedEarly: $stopped,
        );
    }

    /**
     * Start (or resume) processing: `processing`, then the chunk chain and the error report, after commit.
     */
    public function run(LeadImport $import): void
    {
        $locked = DB::transaction(function () use ($import): LeadImport {
            $locked = $this->lock($import);
            $status = $this->statusOf($locked);

            if (! in_array($status->value, ['validated', 'processing'], true)) {
                throw CrmRuleException::refuse('import', 'Validate the import before running it.');
            }

            $locked->forceFill([
                'status' => LeadImportStatus::from('processing'),
                'started_at' => $locked->getAttribute('started_at') ?? CarbonImmutable::now(),
            ])->save();

            $this->audit($locked, 'Lead import started', ['attributes' => ['total_rows' => (int) $locked->getAttribute('total_rows')]], 'leads');

            return $locked;
        });

        $import->setRawAttributes($locked->getAttributes(), true);

        $total = max(0, (int) $locked->getAttribute('total_rows'));
        $jobs = [];

        for ($offset = 0; $offset < $total; $offset += self::CHUNK_SIZE) {
            $jobs[] = new ProcessLeadImportChunk((int) $locked->getKey(), $offset, self::CHUNK_SIZE);
        }

        $jobs[] = new BuildLeadImportErrorReport((int) $locked->getKey());

        Bus::chain($jobs)->dispatch();
    }

    /**
     * Process rows `offset + 1 … offset + size` — the body of `ProcessLeadImportChunk`. Returns rows acted on.
     */
    public function processChunk(int $importId, int $offset, int $size): int
    {
        $import = LeadImport::query()->find($importId);

        if (! $import instanceof LeadImport || $this->statusOf($import)->value !== 'processing') {
            return 0;
        }

        $done = 0;

        foreach ($this->rows($import) as $rowNumber => $raw) {
            if ($rowNumber <= $offset) {
                continue;
            }

            if ($rowNumber > $offset + $size) {
                break;
            }

            // A cancel takes effect between rows, not only between chunks.
            if ($done % 25 === 0 && LeadImport::query()->whereKey($importId)->toBase()->value('status') !== 'processing') {
                break;
            }

            if ($this->processRow($import, (int) $rowNumber, $raw)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * One row, one transaction. Returns false when the row had already been processed (a retried chunk).
     *
     * @param  array<string, string|null>  $raw
     */
    public function processRow(LeadImport $import, int $rowNumber, array $raw): bool
    {
        return DB::transaction(function () use ($import, $rowNumber, $raw): bool {
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s');

            LeadImportRow::query()->toBase()->insertOrIgnore([
                'lead_import_id' => (int) $import->getKey(),
                'row_number' => $rowNumber,
                'raw' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => LeadImportRowStatus::from('pending')->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = LeadImportRow::query()
                ->where('lead_import_id', $import->getKey())
                ->where('row_number', $rowNumber)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof LeadImportRow || $this->rowStatusOf($row)->value !== 'pending') {
                return false;
            }

            $mapped = $this->mapRow($import, $raw);
            $outcome = ['status' => 'failed', 'lead_id' => null, 'duplicate_lead_id' => null, 'duplicate_match_type' => null, 'errors' => null];

            try {
                $outcome = DB::transaction(fn (): array => $this->applyRow($import, $rowNumber, $mapped));
            } catch (ValidationException $exception) {
                $outcome['status'] = 'skipped_invalid';
                $outcome['errors'] = $exception->errors();
            } catch (Throwable $exception) {
                report($exception);
                $outcome['errors'] = ['_row' => [class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 300)]];
            }

            LeadImportRow::query()->whereKey($row->getKey())->toBase()->update([
                'status' => $outcome['status'],
                'lead_id' => $outcome['lead_id'],
                'duplicate_lead_id' => $outcome['duplicate_lead_id'],
                'duplicate_match_type' => $outcome['duplicate_match_type'],
                'errors' => $outcome['errors'] === null ? null : json_encode($outcome['errors'], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            $counter = match ($outcome['status']) {
                'created' => 'created_count',
                'updated' => 'updated_count',
                'skipped_duplicate', 'skipped_invalid' => 'skipped_count',
                default => 'failed_count',
            };

            LeadImport::query()->whereKey($import->getKey())->toBase()->increment($counter);

            return true;
        });
    }

    /**
     * `ProcessLeadImportChunk::failed()`: every still-pending row of the chunk becomes `failed` with the exception
     * class. The import stays `processing`, so `run()` resumes the rest.
     */
    public function failChunk(int $importId, int $offset, int $size, Throwable $exception): void
    {
        $rows = LeadImportRow::query()
            ->where('lead_import_id', $importId)
            ->whereBetween('row_number', [$offset + 1, $offset + $size])
            ->where('status', LeadImportRowStatus::from('pending')->value)
            ->pluck('id');

        foreach ($rows as $id) {
            DB::transaction(static function () use ($id, $importId, $exception): void {
                $updated = LeadImportRow::query()
                    ->whereKey($id)
                    ->where('status', LeadImportRowStatus::from('pending')->value)
                    ->toBase()
                    ->update([
                        'status' => LeadImportRowStatus::from('failed')->value,
                        'errors' => json_encode(['_row' => [class_basename($exception)]]),
                        'updated_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
                    ]);

                if ($updated > 0) {
                    LeadImport::query()->whereKey($importId)->toBase()->increment('failed_count');
                }
            });
        }
    }

    /**
     * The end of the chain: error report, final status, `LeadImportCompleted`. A cancelled import keeps its status.
     */
    public function finish(int $importId): ?LeadImport
    {
        $import = LeadImport::query()->find($importId);

        if (! $import instanceof LeadImport) {
            return null;
        }

        $status = $this->statusOf($import);

        if ($status->isFinished() && $status->value !== 'cancelled') {
            return $import;
        }

        $reportPath = $this->hasErrorRows($import) ? $this->writeErrorReport($import) : null;

        return DB::transaction(function () use ($import, $reportPath): LeadImport {
            $locked = $this->lock($import);
            $status = $this->statusOf($locked);

            $values = ['error_report_path' => $reportPath, 'finished_at' => $locked->getAttribute('finished_at') ?? CarbonImmutable::now()];

            if ($status->value === 'processing') {
                $withErrors = (int) $locked->getAttribute('failed_count') > 0 || $this->hasErrorRows($locked);
                $values['status'] = LeadImportStatus::from($withErrors ? 'completed_with_errors' : 'completed');
            }

            $locked->forceFill($values)->save();

            if ($status->value === 'processing') {
                event(new LeadImportCompleted($locked));
            }

            return $locked;
        });
    }

    /**
     * Stop an import. Leads already created are kept; returns how many.
     */
    public function cancel(LeadImport $import, string $reason): int
    {
        $reason = $this->cleanText($reason, 300);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired('reason', 'Say why the import is being cancelled.');
        }

        return DB::transaction(function () use ($import, $reason): int {
            $locked = $this->lock($import);
            $status = $this->statusOf($locked);

            if ($status->isFinished()) {
                throw CrmRuleException::refuse('import', 'This import has already finished.');
            }

            $kept = (int) $locked->getAttribute('created_count');

            $locked->forceFill([
                'status' => LeadImportStatus::from('cancelled'),
                'finished_at' => CarbonImmutable::now(),
                'failure_message' => mb_substr(sprintf('Cancelled: %s. %d lead(s) already created were kept.', $reason, $kept), 0, 500),
            ])->withReason($reason)->save();

            $import->setRawAttributes($locked->getAttributes(), true);

            return $kept;
        });
    }

    /**
     * The sample CSV with the exact expected headers.
     */
    public function template(): StreamedResponse
    {
        $headers = array_keys(self::TARGET_FIELDS);

        $sample = [
            'name' => 'Ayesha Khan',
            'company' => 'Khan Traders',
            'email' => 'ayesha@example.com',
            'phone' => '0300 1234567',
            'whatsapp' => '0300 1234567',
            'country' => 'Pakistan',
            'country_code' => 'PK',
            'interested_service' => 'Website development',
            'budget_amount' => '150000.00',
            'source' => 'facebook',
            'source_detail' => 'Spring campaign',
            'notes' => 'Wants a quote by Friday',
            'referral_code' => '',
            'status' => 'new',
            'assigned_to_email' => '',
        ];

        return (new CsvWriter)->download('lead-import-template.csv', $headers, [array_values($sample)]);
    }

    /**
     * Stream the error CSV of failed and invalid rows (built on the fly when the stored copy is missing).
     */
    public function errorReport(LeadImport $import): StreamedResponse
    {
        $path = (string) $import->getAttribute('error_report_path');
        $disk = $this->storage->disk(self::DISK);
        $name = sprintf('lead-import-%d-errors.csv', (int) $import->getKey());

        if ($path !== '' && $disk->exists($path)) {
            /** @var StreamedResponse $response */
            $response = $disk->download($path, $name, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);

            return $response;
        }

        if (! $this->hasErrorRows($import)) {
            abort(Response::HTTP_NOT_FOUND, 'This import has no failed rows.');
        }

        [$headers, $rows] = $this->errorRows($import);

        return (new CsvWriter)->download($name, $headers, $rows);
    }

    /**
     * Build and store the error CSV; returns its private path.
     */
    public function writeErrorReport(LeadImport $import): string
    {
        $path = sprintf('%s/errors/%d-%s.csv', self::DIRECTORY, (int) $import->getKey(), Str::lower((string) Str::ulid()));
        $disk = $this->storage->disk(self::DISK);

        [$headers, $rows] = $this->errorRows($import);

        $disk->makeDirectory(dirname($path));
        (new CsvWriter)->toFile($disk->path($path), $headers, $rows);

        return $path;
    }

    /**
     * `crm:prune-imports`: staged files past `crm.import_file_retention_days` and row logs past
     * `crm.import_row_retention_days`. Never deletes a lead.
     *
     * @return array{files: int, rows: int}
     */
    public function prune(CarbonInterface $now): array
    {
        $fileCutoff = CarbonImmutable::instance($now)->subDays($this->crmInt('import_file_retention_days', 30, 1));
        $rowCutoff = CarbonImmutable::instance($now)->subDays($this->crmInt('import_row_retention_days', 90, 1));
        $disk = $this->storage->disk(self::DISK);
        $files = 0;

        LeadImport::query()
            ->withTrashed()
            ->where('created_at', '<', $fileCutoff)
            ->whereNotIn('status', ['processing', 'validating'])
            ->orderBy('id')
            ->each(static function (LeadImport $import) use ($disk, &$files): void {
                foreach (['stored_path', 'error_report_path'] as $column) {
                    $path = (string) $import->getAttribute($column);

                    if ($path !== '' && $disk->exists($path)) {
                        $disk->delete($path);
                        $files++;
                    }
                }
            });

        $rows = 0;

        do {
            // Mass delete through the query builder: the model forbids deleting a single log row by hand.
            $batch = LeadImportRow::query()->where('created_at', '<', $rowCutoff)->limit(1000)->toBase()->delete();
            $rows += $batch;
        } while ($batch === 1000);

        return ['files' => $files, 'rows' => $rows];
    }

    /**
     * Rows of the stored file, keyed by 1-based row number (header excluded), each header => value.
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(LeadImport $import): Generator
    {
        $headers = null;
        $number = 0;

        foreach ($this->parse($this->content($import), (string) $import->getAttribute('delimiter')) as $index => $cells) {
            if ($index === 0) {
                $headers = $this->normaliseHeaders($cells);

                continue;
            }

            $number++;
            $row = [];

            foreach ($headers ?? [] as $position => $header) {
                $value = isset($cells[$position]) ? trim((string) $cells[$position]) : '';
                $row[$header] = $value === '' ? null : $value;
            }

            yield $number => $row;
        }
    }

    /**
     * @param  array<string, string|null>  $mapped
     * @return array{status: string, lead_id: int|null, duplicate_lead_id: int|null, duplicate_match_type: string|null, errors: array<string, list<string>>|null}
     */
    private function applyRow(LeadImport $import, int $rowNumber, array $mapped): array
    {
        $errors = $this->rowErrors($mapped);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $strategy = $this->strategyOf($import);
        $match = $this->leadMatch($mapped);
        $defaults = $this->defaultsOf($import);
        $importerId = $import->getAttribute('created_by') === null ? null : (int) $import->getAttribute('created_by');

        if ($match !== null && $strategy->value === 'skip') {
            return ['status' => 'skipped_duplicate', 'lead_id' => null, 'duplicate_lead_id' => $match['id'], 'duplicate_match_type' => $match['type'], 'errors' => null];
        }

        if ($match !== null && $strategy->value === 'update_existing') {
            $existing = Lead::query()->withoutGlobalScope(LeadVisibilityScope::class)->whereKey($match['id'])->first();

            if ($existing instanceof Lead) {
                $supplied = array_values(array_intersect(
                    array_keys(array_filter($mapped, static fn (?string $value): bool => $value !== null)),
                    array_diff(array_keys(LeadData::PROFILE_FIELDS), ['budget_amount']),
                ));

                $this->leads->update($existing, $this->leadDataFor($import, $rowNumber, $mapped, $defaults, $importerId)->with(['provided' => $supplied]));

                return ['status' => 'updated', 'lead_id' => (int) $existing->getKey(), 'duplicate_lead_id' => $match['id'], 'duplicate_match_type' => $match['type'], 'errors' => null];
            }
        }

        $data = $this->leadDataFor($import, $rowNumber, $mapped, $defaults, $importerId);

        if ($match !== null) {
            $data = $data->with(['duplicateOfLeadId' => $match['id']]);
        }

        $lead = $this->leads->create($data);

        return [
            'status' => 'created',
            'lead_id' => (int) $lead->getKey(),
            'duplicate_lead_id' => $match['id'] ?? null,
            'duplicate_match_type' => $match['type'] ?? null,
            'errors' => null,
        ];
    }

    /**
     * @param  array<string, string|null>  $mapped
     * @param  array<string, mixed>  $defaults
     */
    private function leadDataFor(LeadImport $import, int $rowNumber, array $mapped, array $defaults, ?int $importerId): LeadData
    {
        $assignee = null;

        if ($mapped['assigned_to_email'] ?? null) {
            $assignee = User::query()->active()->where('email', mb_strtolower((string) $mapped['assigned_to_email']))->value('id');
        }

        $assignee ??= is_numeric($defaults['assigned_to'] ?? null) ? (int) $defaults['assigned_to'] : null;

        $status = LeadStatus::tryFrom((string) ($mapped['status'] ?? '')) ?? LeadStatus::tryFrom((string) ($defaults['status'] ?? ''));

        if ($status instanceof LeadStatus && $status->isTerminal()) {
            $status = null;
        }

        $source = InquirySource::tryFrom(mb_strtolower((string) ($mapped['source'] ?? '')))
            ?? InquirySource::tryFrom((string) ($defaults['source'] ?? ''));

        $budget = $mapped['budget_amount'] ?? null;

        return new LeadData(
            name: (string) ($mapped['name'] ?? ''),
            company: $mapped['company'] ?? null,
            email: $mapped['email'] ?? null,
            phone: $mapped['phone'] ?? null,
            whatsapp: $mapped['whatsapp'] ?? null,
            country: $mapped['country'] ?? null,
            countryCode: isset($mapped['country_code']) ? strtoupper((string) $mapped['country_code']) : null,
            interestedService: $mapped['interested_service'] ?? null,
            budgetAmount: $budget === null ? null : Money::of(str_replace([',', ' '], '', $budget)),
            source: $source,
            sourceDetail: $mapped['source_detail'] ?? null,
            notes: $mapped['notes'] ?? null,
            assignedTo: $assignee === null ? null : (int) $assignee,
            status: $status,
            referralCode: $mapped['referral_code'] ?? null,
            leadImportId: (int) $import->getKey(),
            confirmDuplicate: true,
            meta: ['row_number' => $rowNumber, 'file' => (string) $import->getAttribute('original_filename')],
            createdBy: $importerId,
        );
    }

    /**
     * The first live lead matching the row's contacts, or null.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{id: int, type: string}|null
     */
    private function leadMatch(array $mapped): ?array
    {
        $candidate = new ContactCandidate(
            phone: $mapped['phone'] ?? null,
            whatsapp: $mapped['whatsapp'] ?? null,
            email: $mapped['email'] ?? null,
            countryCode: isset($mapped['country_code']) ? strtoupper((string) $mapped['country_code']) : null,
        );

        if ($candidate->isEmpty()) {
            return null;
        }

        $match = $this->duplicates->check($candidate, null, null, asSystem: true)->firstLeadMatch();

        return $match === null || $match->id === null ? null : ['id' => $match->id, 'type' => $match->matchType->value];
    }

    /**
     * Field-level errors, from the same rules the lead form applies to these columns.
     *
     * @param  array<string, string|null>  $mapped
     * @return array<string, list<string>>
     */
    private function rowErrors(array $mapped): array
    {
        $budget = $mapped['budget_amount'] ?? null;

        if ($budget !== null) {
            $mapped['budget_amount'] = str_replace([',', ' '], '', $budget);
        }

        if (isset($mapped['source'])) {
            $mapped['source'] = mb_strtolower((string) $mapped['source']);
        }

        $validator = Validator::make($mapped, [
            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-.\s]{5,32}$/'],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-.\s]{5,32}$/'],
            'country' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'interested_service' => ['nullable', 'string', 'max:150'],
            'budget_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'source' => ['nullable', Rule::enum(InquirySource::class)],
            'source_detail' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'referral_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'status' => ['nullable', Rule::in(array_map(static fn (LeadStatus $s): string => $s->value, LeadStatus::open()))],
            'assigned_to_email' => ['nullable', 'email', Rule::exists('users', 'email')->whereNull('deleted_at')],
        ]);

        if (! $validator->fails()) {
            return [];
        }

        /** @var array<string, list<string>> $messages */
        $messages = $validator->errors()->toArray();

        return $messages;
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array<string, string|null>
     */
    private function mapRow(LeadImport $import, array $raw): array
    {
        $mapped = [];

        foreach ((array) $import->getAttribute('column_map') as $header => $field) {
            if (! is_string($field) || ! array_key_exists($field, self::TARGET_FIELDS)) {
                continue;
            }

            $mapped[$field] = $raw[(string) $header] ?? null;
        }

        return $mapped;
    }

    /**
     * @param  array<string, string|null>  $raw
     * @param  array<string, list<string>>  $errors
     * @param  array{id: int, type: string}|null  $match
     */
    private function recordPreviewRow(LeadImport $import, int $rowNumber, array $raw, array $errors, ?array $match, LeadImportDuplicateStrategy $strategy): void
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s');
        $values = [
            'raw' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => LeadImportRowStatus::from('pending')->value,
            'duplicate_lead_id' => $match['id'] ?? null,
            'duplicate_match_type' => $match['type'] ?? null,
            'errors' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        $inserted = LeadImportRow::query()->toBase()->insertOrIgnore([
            ...$values,
            'lead_import_id' => (int) $import->getKey(),
            'row_number' => $rowNumber,
            'created_at' => $now,
        ]);

        if ($inserted === 0) {
            // Re-validation after a mapping change: only a row the run has not touched is refreshed; `raw` and the
            // row number are fixed at insert.
            unset($values['raw']);

            LeadImportRow::query()
                ->where('lead_import_id', $import->getKey())
                ->where('row_number', $rowNumber)
                ->where('status', LeadImportRowStatus::from('pending')->value)
                ->toBase()
                ->update($values);
        }
    }

    private function hasErrorRows(LeadImport $import): bool
    {
        return LeadImportRow::query()
            ->where('lead_import_id', $import->getKey())
            ->whereIn('status', [LeadImportRowStatus::from('skipped_invalid')->value, LeadImportRowStatus::from('failed')->value])
            ->exists();
    }

    /**
     * @return array{0: list<string>, 1: Generator<int, array<int, mixed>>}
     */
    private function errorRows(LeadImport $import): array
    {
        $headers = $this->headers($import);

        $query = LeadImportRow::query()
            ->where('lead_import_id', $import->getKey())
            ->whereIn('status', [LeadImportRowStatus::from('skipped_invalid')->value, LeadImportRowStatus::from('failed')->value]);

        $rows = CsvWriter::rowsFrom($query, static function (LeadImportRow $row) use ($headers): array {
            $raw = (array) $row->getAttribute('raw');
            $messages = [];

            foreach ((array) $row->getAttribute('errors') as $field => $list) {
                foreach ((array) $list as $message) {
                    $messages[] = ($field === '_row' ? '' : $field.': ').$message;
                }
            }

            $status = $row->getAttribute('status');

            return [
                (int) $row->getAttribute('row_number'),
                ...array_map(static fn (string $header): mixed => $raw[$header] ?? null, $headers),
                $status instanceof LeadImportRowStatus ? $status->label() : (string) $status,
                implode(' | ', $messages),
            ];
        });

        return [['row_number', ...$headers, 'result', 'errors'], $rows];
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function validMap(array $map, array $headers): array
    {
        $clean = [];
        $used = [];

        foreach ($map as $header => $field) {
            $header = (string) $header;

            if ($field === '' || $field === null) {
                continue;
            }

            if (! in_array($header, $headers, true)) {
                throw CrmRuleException::refuse('column_map', sprintf('The file has no column named "%s".', $header));
            }

            if (! is_string($field) || ! array_key_exists($field, self::TARGET_FIELDS)) {
                throw CrmRuleException::refuse('column_map', sprintf('"%s" is not a lead field that can be imported.', (string) $field));
            }

            if (in_array($field, $used, true)) {
                throw CrmRuleException::refuse('column_map', sprintf('Only one column may fill "%s".', $field));
            }

            $used[] = $field;
            $clean[$header] = $field;
        }

        if (! in_array('name', $used, true)) {
            throw CrmRuleException::refuse('column_map', 'Choose the column that holds the lead\'s name.');
        }

        return $clean;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function suggestMap(array $headers): array
    {
        $map = [];
        $used = [];

        foreach ($headers as $header) {
            $raw = mb_strtolower(trim($header));
            $key = trim((string) preg_replace('/[_\-]+/', ' ', $raw));

            foreach (self::TARGET_FIELDS as $field => $aliases) {
                $matches = $key === str_replace('_', ' ', $field) || in_array($key, $aliases, true) || in_array($raw, $aliases, true);

                if (! in_array($field, $used, true) && $matches) {
                    $map[$header] = $field;
                    $used[] = $field;

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return list<string>
     */
    private function normaliseHeaders(array $cells): array
    {
        $headers = [];
        $seen = [];

        foreach (array_values($cells) as $position => $cell) {
            $header = trim((string) $cell);
            $header = $header === '' ? 'column_'.($position + 1) : mb_substr($header, 0, 100);
            $base = $header;
            $suffix = 2;

            while (isset($seen[mb_strtolower($header)])) {
                $header = $base.' ('.$suffix++.')';
            }

            $seen[mb_strtolower($header)] = true;
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * Every non-blank CSV record of `$content`, index 0 being the header.
     *
     * @return Generator<int, list<string|null>>
     */
    private function parse(string $content, string $delimiter): Generator
    {
        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            throw new RuntimeException('The import file could not be opened.');
        }

        try {
            fwrite($handle, $content);
            rewind($handle);

            $index = 0;
            $delimiter = $delimiter === '' ? ',' : mb_substr($delimiter, 0, 1);

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($cells === [null] || array_filter($cells, static fn (?string $cell): bool => trim((string) $cell) !== '') === []) {
                    continue;
                }

                yield $index++ => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    private function content(LeadImport $import): string
    {
        $path = (string) $import->getAttribute('stored_path');
        $disk = $this->storage->disk(self::DISK);

        if ($path === '' || ! $disk->exists($path)) {
            throw CrmRuleException::refuse('import', 'The uploaded file is no longer available. Upload it again.');
        }

        return (string) $disk->get($path);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function toUtf8(string $bytes, ?string $encoding): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return [substr($bytes, 3), 'UTF-8'];
        }

        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $source = str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';

            return [(string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', $source), $source];
        }

        $encoding = $encoding === null ? null : strtoupper(trim($encoding));

        if (($encoding === null || $encoding === 'UTF-8') && mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, 'UTF-8'];
        }

        $source = $encoding !== null && $encoding !== 'UTF-8' && in_array($encoding, array_map('strtoupper', mb_list_encodings()), true)
            ? $encoding
            : 'Windows-1252';

        return [(string) mb_convert_encoding($bytes, 'UTF-8', $source), $source];
    }

    private function sniffDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n");
        $firstLine = is_string($firstLine) ? $firstLine : $content;
        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function assertCsvName(string $name): void
    {
        $segments = array_map('strtolower', explode('.', $name));
        $extension = count($segments) > 1 ? (string) end($segments) : '';

        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw CrmRuleException::refuse('file', 'Upload a .csv file.');
        }

        foreach (array_slice($segments, 1, -1) as $inner) {
            if (in_array($inner, SettingsRegistry::NEVER_UPLOADABLE_EXTENSIONS, true) || preg_match('/^(php\d*|phtml|svg)$/', $inner) === 1) {
                throw CrmRuleException::refuse('file', 'The file name carries a second, unsafe extension.');
            }
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return mb_substr(trim($name) === '' ? 'import.csv' : trim($name), 0, 255);
    }

    private function lock(LeadImport $import): LeadImport
    {
        /** @var LeadImport $locked */
        $locked = LeadImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function statusOf(LeadImport $import): LeadImportStatus
    {
        $status = $import->getAttribute('status');

        return $status instanceof LeadImportStatus ? $status : LeadImportStatus::from((string) $status);
    }

    private function rowStatusOf(LeadImportRow $row): LeadImportRowStatus
    {
        $status = $row->getAttribute('status');

        return $status instanceof LeadImportRowStatus ? $status : LeadImportRowStatus::from((string) $status);
    }

    private function strategyOf(LeadImport $import): LeadImportDuplicateStrategy
    {
        $strategy = $import->getAttribute('duplicate_strategy');

        return $strategy instanceof LeadImportDuplicateStrategy ? $strategy : LeadImportDuplicateStrategy::from((string) ($strategy ?: 'import_and_flag'));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsOf(LeadImport $import): array
    {
        $defaults = $import->getAttribute('defaults');

        return is_array($defaults) ? $defaults : [];
    }
}
