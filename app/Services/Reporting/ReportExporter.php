<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ExportFormat;
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
