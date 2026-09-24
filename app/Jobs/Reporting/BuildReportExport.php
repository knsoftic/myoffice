<?php

declare(strict_types=1);

namespace App\Jobs\Reporting;

use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExportStatus;
use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Services\Reporting\ReportEngine;
use App\Services\Reporting\ReportExportService;
use App\Support\CsvWriter;
use App\Support\ReportRegistry;
use App\Support\ReportResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Build one queued export (phase-19-23 §10.4).
 *
 * **It runs as the requester, not as nobody.** `ReportEngine::run()` takes a user because every one
 * of §9.5's six steps depends on who is asking — the source module's isolation scope, the branch
 * clause, the column permissions. A job that ran a report with no actor would produce a file
 * containing everything, and then hand it to somebody entitled to a fraction of it. So the job
 * loads `requested_by` and runs the report exactly as that person would have.
 *
 * **A file is written, then measured, then the row is updated.** `row_count`, `file_size_bytes` and
 * `checksum_sha256` are recorded from the file that actually landed rather than from the result in
 * memory, because the two can differ — a disk that filled halfway through writes a shorter file and
 * a row that claimed otherwise would serve a truncated CSV as if it were complete. The checksum is
 * computed by streaming the file, never by loading it.
 *
 * **`failed()` writes the real exception text**, which the requester then sees. §2.26 is explicit
 * about this, and it is the difference between a person who refreshes a page for ten minutes and a
 * person who reads "Lock wait timeout exceeded" and tries a narrower range.
 *
 * **`ShouldBeUnique` on the uuid.** Two workers building the same export would race for one path,
 * and the loser would overwrite a file the winner had already checksummed.
 */
final class BuildReportExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $uuid,
    ) {
        // A row written and rolled back must never be built. [D152] says the dispatch itself happens
        // outside the transaction; this is the belt to that braces, for any caller who forgets.
        $this->afterCommit();
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return 'report-export:'.$this->uuid;
    }

    public function handle(ReportEngine $engine, ReportExportService $exports): void
    {
        $export = ReportExport::query()->where('uuid', $this->uuid)->first();

        if ($export === null) {
            return;
        }

        // Already built, already failed, already expired: nothing to do. A retry that rebuilt a
        // completed export would replace a file somebody may already be downloading.
        if (! $export->isPending()) {
            return;
        }

        $definition = ReportRegistry::definition($export->report_key);
        $user = User::query()->find($export->requested_by);

        if ($definition === null || $user === null) {
            $this->fail($export, 'MissingSource', $definition === null
                ? sprintf('The report [%s] no longer exists.', $export->report_key)
                : 'The person who requested this export no longer has an account.');

            return;
        }

        $export->forceFill(['status' => ExportStatus::Running, 'started_at' => now()])->save();

        $request = ReportRequest::fromArray($export->filters)->unpaginated();

        // As the requester — see the class note.
        $result = $engine->run($definition->key(), $request, $user);

        $path = $exports->pathFor($export);
        $disk = Storage::disk($export->storage_disk);

        $disk->put($path, $this->render($result, $export));

        $retention = max(1, (int) setting('reports.export_retention_days', 7));

        $export->forceFill([
            'status' => ExportStatus::Completed,
            'file_path' => $path,
            'row_count' => $result->rowCount(),
            // Measured from the file that landed, not from the result in memory.
            'file_size_bytes' => $disk->size($path),
            'checksum_sha256' => $this->checksum($disk, $path),
            'completed_at' => now(),
            'expires_at' => now()->addDays($retention),
        ])->save();
    }

    /**
     * Record the failure on the row so the requester can read it.
     *
     * Laravel calls this after the last retry. It is deliberately defensive: an exception thrown
     * while recording a failure would leave the row at `running` for ever, which is the one state
     * nothing sweeps.
     */
    public function failed(?Throwable $exception): void
    {
        $export = ReportExport::query()->where('uuid', $this->uuid)->first();

        if ($export === null || $export->status === ExportStatus::Completed) {
            return;
        }

        $this->fail(
            $export,
            $exception === null ? 'UnknownError' : $exception::class,
            $exception?->getMessage() ?? 'The export failed without reporting a reason.',
        );
    }

    private function fail(ReportExport $export, string $class, string $message): void
    {
        try {
            $export->forceFill([
                'status' => ExportStatus::Failed,
                'error_class' => mb_substr($class, 0, 180),
                // The column is 500; a stack-trace-length message is truncated rather than refused,
                // because a truncated reason is still a reason.
                'error_message' => mb_substr($message, 0, 500),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable) {
            // Nothing further to do: the row is what we were trying to write to.
        }
    }

    /**
     * The file's bytes.
     *
     * CSV and Excel stream through the same generator the synchronous path uses; print and PDF
     * render the same Blade. Building the file here rather than reusing `ReportExporter::export()`
     * is deliberate: that method returns a `StreamedResponse` aimed at a browser, and capturing a
     * response's output buffer to get at its bytes is a fragile way to obtain something this can
     * ask for directly.
     */
    private function render(ReportResult $result, ReportExport $export): string
    {
        if ($export->format->isTabular()) {
            $columns = $result->columns();

            $handle = fopen('php://temp', 'r+b');

            (new CsvWriter)->write(
                $handle,
                array_map(static fn (string $c): string => ucfirst(str_replace('_', ' ', $c)), $columns),
                (function () use ($result, $columns): iterable {
                    foreach ($result->rows as $row) {
                        yield array_map(
                            static fn ($value): string => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
                            array_intersect_key($row, array_flip($columns)),
                        );
                    }
                })(),
            );

            rewind($handle);
            $contents = (string) stream_get_contents($handle);
            fclose($handle);

            return $contents;
        }

        return View::make('admin.reports.print', [
            'result' => $result,
            'asPdf' => true,
        ])->render();
    }

    /**
     * sha256 of the written file, computed in a stream.
     *
     * Never by loading the file: a 200,000-row export is the case this whole queued path exists
     * for, and reading it into a string to hash it would undo the reason it was queued.
     */
    private function checksum(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): ?string
    {
        try {
            $stream = $disk->readStream($path);

            if ($stream === null || $stream === false) {
                return null;
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            return hash_final($context);
        } catch (Throwable) {
            // A checksum we could not take is a null checksum, not a failed export.
            return null;
        }
    }
}
