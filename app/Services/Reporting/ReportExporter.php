<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ExportFormat;
use App\Services\Reporting\Exceptions\ExportFormatUnavailableException;
use App\Support\CsvWriter;
use App\Support\ReportResult;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one exporter every report in this system goes through (phase-13 §6.7.5, §6.9, F-4.14).
 *
 * **Namespaced `Reporting`, not `Finance`, on purpose.** Phases 18 and 19–23 export reports that have
 * nothing to do with money through this same class; putting it under `Finance` would have guaranteed a
 * second copy the first time a non-finance report needed a CSV, and the two would have disagreed about
 * whether the meta block travels with the file.
 *
 * **Nothing is ever collected into memory.** A `ReportResult` already holds its rows — the caller is
 * responsible for not building one bigger than `finance.report_sync_row_limit` — but the writing side
 * streams, so a large result is handed to the client as it is produced rather than assembled first.
 *
 * **The meta travels with the file.** A spreadsheet that does not say what period it covered, on which
 * date column, and what it could not include is a figure somebody will quote out of context six months
 * from now.
 */
final class ReportExporter
{
    public function __construct(
        private readonly ViewFactory $views,
    ) {}

    /**
     * @param  array<string, mixed>  $context  extra variables the print view needs (`type`, `range`, …)
     */
    public function export(
        ReportResult $result,
        ExportFormat $format,
        string $name = 'report',
        string $printView = 'admin.reports.finance.print',
        array $context = [],
    ): StreamedResponse {
        return match ($format) {
            ExportFormat::Csv => $this->csv($result, $name),
            // phase-19-23 3.5: Phase 23 added the `excel` case to the enum, so this match has to
            // answer for it or the first Excel request becomes an UnhandledMatchError. It is the
            // same column set through the same generator - see self::excel().
            ExportFormat::Excel => $this->excel($result, $name),
            // `print` and `pdf` render the same Blade. A PDF that had drifted from the printed page
            // would be a second document claiming to be the first.
            ExportFormat::Print, ExportFormat::Pdf => $this->rendered($result, $format, $name, $printView, $context),
        };
    }

    public function filename(ReportResult $result, ExportFormat $format, string $name = 'report'): string
    {
        return sprintf(
            '%s-%s-to-%s.%s',
            $name,
            (string) ($result->meta['from'] ?? 'start'),
            (string) ($result->meta['to'] ?? 'end'),
            $format->extension(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The formats
    |--------------------------------------------------------------------------
    */

    private function csv(ReportResult $result, string $name): StreamedResponse
    {
        $columns = $result->columns();

        return (new CsvWriter)->download(
            $this->filename($result, ExportFormat::Csv, $name),
            array_map(static fn (string $c): string => ucfirst(str_replace('_', ' ', $c)), $columns),
            function () use ($result, $columns): iterable {
                foreach ($result->rows as $row) {
                    yield array_map(
                        static fn ($value): string => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
                        array_intersect_key($row, array_flip($columns)),
                    );
                }

                yield from $this->metaRows($result);
            },
        );
    }

    /**
     * A single-sheet workbook (phase-19-23 6.21).
     *
     * **Deliberately the same column set and the same generator as the CSV.** Two export paths that
     * built their own row lists would drift, and the first thing to drift would be whether a
     * withheld money column is absent or blank - which is the one difference INV-23-2 says must
     * never appear.
     *
     * **A missing package is refused by name, not by a 500.** The screen never offers this format
     * unless `reports.excel_enabled` is on *and* a writer is installed
     * ({@see ExportFormat::isAvailable()}), so arriving here without one means a hand-built URL or a
     * queued export whose package was removed while it waited. Either way the person deserves a
     * sentence rather than a stack trace.
     */
    private function excel(ReportResult $result, string $name): StreamedResponse
    {
        if (! ExportFormat::Excel->isAvailable()) {
            throw ExportFormatUnavailableException::for(ExportFormat::Excel);
        }

        // The writer is resolved by name rather than imported: importing a class an installation may
        // not have is how an optional dependency becomes a required one at autoload time.
        $writer = 'OpenSpout' . chr(92) . 'Writer' . chr(92) . 'XLSX' . chr(92) . 'Writer';

        if (! class_exists($writer)) {
            // `isAvailable()` also accepts maatwebsite/excel, which wraps this one; if neither
            // concrete writer is present the switch was on without the package behind it.
            throw ExportFormatUnavailableException::for(ExportFormat::Excel);
        }

        $columns = $result->columns();
        $filename = $this->filename($result, ExportFormat::Excel, $name);

        return new StreamedResponse(
            function () use ($result, $columns, $writer): void {
                $sheet = new $writer();
                $sheet->openToFile('php://output');

                $row = 'OpenSpout' . chr(92) . 'Common' . chr(92) . 'Entity' . chr(92) . 'Row';

                $sheet->addRow($row::fromValues(
                    array_map(static fn (string $c): string => ucfirst(str_replace('_', ' ', $c)), $columns),
                ));

                foreach ($result->rows as $line) {
                    $sheet->addRow($row::fromValues(array_map(
                        static fn ($value): string => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
                        array_intersect_key($line, array_flip($columns)),
                    )));
                }

                // The same explaining block the CSV carries, so a workbook read six months later
                // says what it covered and what it left out.
                foreach ($this->metaRows($result) as $meta) {
                    $sheet->addRow($row::fromValues(array_map('strval', $meta)));
                }

                $sheet->close();
            },
            200,
            [
                'Content-Type' => ExportFormat::Excel->mime(),
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    /**
     * The printed page, streamed. dompdf is not installed in this release, so `pdf` renders the same
     * HTML the printer gets rather than pretending to produce a file it cannot.
     *
     * @param  array<string, mixed>  $context
     */
    private function rendered(
        ReportResult $result,
        ExportFormat $format,
        string $name,
        string $printView,
        array $context,
    ): StreamedResponse {
        $html = $this->views->make($printView, array_merge($context, [
            'result' => $result,
            'asPdf' => $format === ExportFormat::Pdf,
        ]))->render();

        return new StreamedResponse(
            static function () use ($html): void {
                echo $html;
                flush();
            },
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Report-Name' => $this->filename($result, $format, $name),
            ],
        );
    }

    /**
     * The block that tells a reader what this file actually covered.
     *
     * @return list<list<string>>
     */
    private function metaRows(ReportResult $result): array
    {
        $rows = [
            [],
            ['Basis', (string) ($result->meta['basis'] ?? '')],
            ['Dated on', (string) ($result->meta['date_column'] ?? '')],
            ['Period', sprintf('%s to %s', $result->meta['from'] ?? '', $result->meta['to'] ?? '')],
        ];

        foreach ($result->totals as $key => $value) {
            $rows[] = ['Total — '.str_replace('_', ' ', (string) $key), (string) $value];
        }

        if (($result->meta['filters'] ?? []) !== []) {
            foreach ((array) $result->meta['filters'] as $key => $value) {
                $rows[] = ['Filter — '.str_replace('_', ' ', (string) $key), (string) $value];
            }
        }

        if ($result->omittedSources() !== []) {
            // Named, never silently dropped: a partial total read as a full one is worse than a refusal.
            $rows[] = ['Not included (no permission)', implode('; ', $result->omittedSources())];
        }

        return $rows;
    }
}
