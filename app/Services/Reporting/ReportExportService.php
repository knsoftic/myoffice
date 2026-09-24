<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\DataObjects\Files\StoredFile;
use App\DataObjects\Files\StreamOptions;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\Reporting\BuildReportExport;
use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Reports\Contracts\ReportDefinition;
use App\Services\Files\SecureFileService;
use App\Services\Reporting\Exceptions\ExportFormatUnavailableException;
use App\Support\ReportRegistry;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Exports: stream now, queue for later, or refuse (phase-19-23 §6.21).
 *
 * **This class persists, serves and prunes. It does not decide.** The stream-or-queue-or-refuse
 * branch lives on {@see ReportEngine::export()}, which is the half that already runs the report and
 * knows the row count. Putting it here as well would have made the two classes construct each other
 * - a container cycle that fails at resolve time rather than at compile time, which is the worst
 * moment to find out.
 *
 * **[D152] The job is dispatched after the transaction returns, never inside it.** Laravel drops
 * after-commit callbacks when a *savepoint* commits — so a `dispatch()->afterCommit()` inside a
 * nested transaction is silently never delivered, and the export sits at `queued` for ever with
 * nothing to explain it. The row is written in a transaction; the dispatch happens after that
 * method has returned.
 *
 * **`download()` re-authorises against live state, every time (PH23-16).** Three of its four checks
 * are about the row; the fourth is about the person *now*. Somebody who lost `view_financial`
 * yesterday cannot download yesterday's file today, because the file was shaped by the permissions
 * they held when it was built. That is also why ownership is checked regardless of role: a Super
 * Admin sees the register and still cannot download another user's file, since its contents were
 * narrowed to that user's scope and handing it over would launder the isolation every report
 * applies (§9.5).
 */
final class ReportExportService
{
    /** `exports/reports/{YYYY-MM}/{uuid}.{ext}` — §6.3, binding. */
    private const PATH = 'exports/reports/%s/%s.%s';

    public function __construct(
        private readonly SecureFileService $files,
    ) {}

    /**
     * One `report_exports` row, and the job that fills it.
     *
     * The filters are stored verbatim — §2.26 — so the file can be explained and rebuilt months
     * later. `date_from` / `date_to` are denormalised out of them only because the list screen
     * filters on a date range and JSON cannot be indexed usefully for that.
     */
    public function queue(
        ReportDefinition $definition,
        ReportRequest $request,
        ExportFormat $format,
        User $user,
    ): ReportExport {
        $export = DB::transaction(static fn (): ReportExport => ReportExport::query()->create([
            'report_key' => $definition->key(),
            'format' => $format,
            'filters' => $request->toArray(),
            'date_from' => $request->range->start()->toDateString(),
            'date_to' => $request->range->end()->toDateString(),
            'requested_by' => $user->getKey(),
            'storage_disk' => 'private',
        ]));

        // [D152] — outside the transaction, not `afterCommit()` inside it. See the class note.
        BuildReportExport::dispatch($export->uuid);

        return $export;
    }

    /**
     * Serve a built file, re-checking everything that could have changed since it was built.
     *
     * @throws NotFoundHttpException not this person's export, or no file
     * @throws AccessDeniedHttpException the report's permissions are no longer held
     */
    public function download(ReportExport $export, User $user): StreamedResponse
    {
        // Ownership first, and a 404 rather than a 403: somebody guessing uuids learns nothing from
        // a "forbidden" they would not learn from a "not found", and one of the two confirms the
        // uuid was real. Checked regardless of role — see the class note.
        if ((int) $export->requested_by !== (int) $user->getKey()) {
            throw new NotFoundHttpException('That export does not exist.');
        }

        if (! $export->isDownloadable()) {
            throw new NotFoundHttpException(
                $export->hasExpired()
                    ? 'That file has been removed. Exports are kept for a limited time; run the report again.'
                    : 'That export is not ready to download.',
            );
        }

        // PH23-16: the report's own permissions, now. The file was shaped by what this person could
        // see when it was built, and a right withdrawn since then has to take the file with it.
        $definition = ReportRegistry::definition($export->report_key);

        if ($definition === null) {
            throw new NotFoundHttpException('The report this file came from no longer exists.');
        }

        if (! ReportRegistry::allows($user, $definition)) {
            throw new AccessDeniedHttpException(
                'You no longer have permission to see this report, so its file cannot be downloaded.',
            );
        }

        $gate = app(Gate::class)->forUser($user);

        foreach ($this->financialPermissionsFor($definition) as $permission) {
            if (! $gate->allows($permission)) {
                throw new AccessDeniedHttpException(
                    'This file contains figures you no longer have permission to see. Run the report '
                    .'again to get a copy without them.',
                );
            }
        }

        $disk = Storage::disk($export->storage_disk);

        if (! $disk->exists((string) $export->file_path)) {
            // The row says there is a file and the disk disagrees. Mark it expired rather than
            // 500ing: the honest answer is "the file has gone", and the row is the record that it
            // once existed.
            $export->forceFill(['status' => ExportStatus::Expired])->save();

            throw new NotFoundHttpException('That file is no longer on disk. Run the report again.');
        }

        $export->forceFill([
            'download_count' => $export->download_count + 1,
            'last_downloaded_at' => now(),
        ])->save();

        return $this->files->stream(
            new StoredFile(
                disk: $export->storage_disk,
                path: (string) $export->file_path,
                originalName: $this->filenameFor($export, $definition),
                extension: $export->format->extension(),
                mimeType: $export->format->mime(),
                sizeBytes: (int) $export->file_size_bytes,
                checksumSha256: $export->checksum_sha256,
            ),
            StreamOptions::attachment($this->filenameFor($export, $definition)),
        );
    }

    /**
     * Delete the files of every export whose retention has run out; keep every row.
     *
     * Returns the number of files removed. The row is marked `expired` rather than deleted, so
     * somebody who bookmarked a link is told the file has gone rather than that it never existed —
     * and the record of what left the building outlives the bytes (§2.26).
     */
    public function prune(): int
    {
        $removed = 0;

        ReportExport::query()
            ->dueForPrune()
            ->orderBy('id')
            ->chunkById(200, function ($exports) use (&$removed): void {
                foreach ($exports as $export) {
                    if ($this->forget($export)) {
                        $removed++;
                    }
                }
            });

        return $removed;
    }

    /**
     * Drop one export's bytes and mark the row.
     *
     * The row is updated **whether or not** the file was there: a file already gone still means the
     * export has expired, and leaving the row `completed` would have the sweep pick it up again
     * every night for ever.
     */
    private function forget(ReportExport $export): bool
    {
        $deleted = false;

        try {
            $disk = Storage::disk($export->storage_disk);

            if ($disk->exists((string) $export->file_path)) {
                $disk->delete((string) $export->file_path);
                $deleted = true;
            }
        } catch (Throwable $exception) {
            // A disk that cannot be reached is not a reason to leave the row claiming a live file.
            Log::warning('Could not remove an expired report export.', [
                'uuid' => $export->uuid,
                'path' => $export->file_path,
                'exception' => $exception->getMessage(),
            ]);
        }

        $export->forceFill([
            'status' => ExportStatus::Expired,
            'file_path' => null,
            'file_size_bytes' => null,
        ])->save();

        return $deleted;
    }

    /**
     * Where this export's file goes. `{YYYY-MM}` so a year of exports is not one flat directory.
     */
    public function pathFor(ReportExport $export): string
    {
        return sprintf(
            self::PATH,
            $export->created_at?->format('Y-m') ?? now()->format('Y-m'),
            $export->uuid,
            $export->format->extension(),
        );
    }

    /**
     * The row count above which this report is queued rather than streamed.
     *
     * **`finance.report_sync_row_limit` still wins for the finance reports** (§5.3, §13.2). Two keys
     * for one idea is a convergence ask, not something to close by quietly changing which one wins:
     * a finance report that suddenly streamed 5,000 rows inline because Phase 23 arrived would be a
     * regression nobody asked for.
     */
    public function syncLimitFor(ReportDefinition $definition): int
    {
        $financeModules = ['invoices', 'project_payments', 'income', 'expenses'];

        if (in_array($definition->module(), $financeModules, true)) {
            $financeLimit = setting('finance.report_sync_row_limit');

            if ($financeLimit !== null && (int) $financeLimit > 0) {
                return (int) $financeLimit;
            }
        }

        return (int) setting('reports.sync_row_limit', 5000);
    }

    /**
     * Every money column's permission on this report.
     *
     * @return list<string>
     */
    private function financialPermissionsFor(ReportDefinition $definition): array
    {
        $permissions = [];

        foreach ($definition->columns() as $column) {
            if ($column->isFinancial() && $column->permission !== null) {
                $permissions[] = $column->permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The name the browser saves it under: the report and the period, not a uuid.
     *
     * A folder of `4f3c….csv` is a folder nobody can use.
     */
    private function filenameFor(ReportExport $export, ReportDefinition $definition): string
    {
        return sprintf(
            '%s-%s-to-%s.%s',
            Str::slug($definition->title()),
            $export->date_from?->toDateString() ?? 'start',
            $export->date_to?->toDateString() ?? 'end',
            $export->format->extension(),
        );
    }
}
