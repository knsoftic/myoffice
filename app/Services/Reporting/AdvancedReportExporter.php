<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\DataObjects\Reporting\AdvancedReportFilters;
use App\Enums\ExportFormat;
use App\Models\User;
use App\Support\CsvWriter;
use App\Support\Format;
use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every file Advanced Reports produces — CSV, Excel, PDF — and the data its printed sheet renders
 * (D177).
 *
 * **One source, four outputs.** Each format is fed by `AdvancedStudentReportService::exportRows()`
 * over the same filter object and the same sorted base query the screen paginates, so "export" means
 * exactly the rows on the screen, in the screen's order — never a second query that could drift.
 * The column list is `exportHeaders()` for the viewer, so a withheld money column is **absent** from
 * every file, not blank (INV-23-2), and the file says it was left out.
 *
 * **Caps, decided before any row is read.** CSV and Excel stop at `reports.export_max_rows` — the
 * same ceiling the report engine obeys. A PDF stops at `AdvancedStudentReportService::PDF_MAX_ROWS`
 * — dompdf lays out every row in memory, inside the request, and runs out of a 512 MB limit well
 * before a thousand rows — and a printed sheet at `PRINT_MAX_ROWS`, because a browser print dialog
 * over sixty pages is not a report anybody reads. Above either the answer is "narrow it, or take the
 * spreadsheet". The refusal is a sentence the controller shows as a toast.
 *
 * **Every file says what it covers.** The CSV and the workbook end with the filters in words, the row
 * count, when and by whom — the same block `ReportExporter` writes, for the same reason: a file read
 * six months later is otherwise a list of names with no question attached. The PDF and the print
 * sheet carry it at the top.
 *
 * **The PDF is a real PDF**, rendered the way `DocumentPdfRenderer` renders one: dompdf with remote
 * fetching, PHP and JavaScript forced off for the render, and a refusal to render at all while the
 * config says remote access is on.
 */
final class AdvancedReportExporter
{
    /**
     * The export columns a spreadsheet should be able to add up — written as numbers in the workbook.
     * Labels from `AdvancedStudentReportService::exportHeaders()`; intersected with the viewer's own
     * headers, so a viewer without money has none.
     */
    private const MONEY_COLUMNS = ['Total Fees', 'Paid Amount', 'Remaining Amount'];

    /** Columns a printed table right-aligns. */
    private const NUMERIC_COLUMNS = ['Progress', 'Total Fees', 'Paid Amount', 'Remaining Amount'];

    private const TITLE = 'Advanced Reports';

    private const SUBTITLE = 'Students, enrolments and fees';

    public function __construct(
        private readonly AdvancedStudentReportService $reports,
        private readonly ViewFactory $views,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Caps
    |--------------------------------------------------------------------------
    */

    /** The most rows `$format` may carry. */
    public function limitFor(ExportFormat $format): int
    {
        return match (true) {
            $format->isTabular() => $this->reports->exportMaxRows(),
            $format === ExportFormat::Pdf => AdvancedStudentReportService::PDF_MAX_ROWS,
            default => AdvancedStudentReportService::PRINT_MAX_ROWS,
        };
    }

    /**
     * Why `$rows` rows are too many for `$format`, as a sentence for a toast — or null when they are
     * not. The way out is named, and only offered when the viewer can take it: a spreadsheet is not a
     * suggestion for somebody who may only print.
     */
    public function refusal(ExportFormat $format, int $rows, User $viewer): ?string
    {
        $limit = $this->limitFor($format);

        if ($rows <= $limit) {
            return null;
        }

        $what = match ($format) {
            ExportFormat::Pdf => 'a PDF',
            ExportFormat::Print => 'a printed sheet',
            default => 'an export',
        };

        $message = sprintf(
            'These filters match %s rows, and %s stops at %s. Narrow the filters — a shorter period, one course or one batch',
            Format::number($rows),
            $what,
            Format::number($limit),
        );

        if (! $format->isTabular() && $viewer->can('advanced_reports.export')) {
            return $message.' — or export it as CSV or Excel, which take up to '.Format::number($this->reports->exportMaxRows()).' rows.';
        }

        return $message.' — and try again.';
    }

    /** `advanced-report-2026-10-05.xlsx` — dated in the business timezone, like every other date here. */
    public function filename(ExportFormat $format): string
    {
        return sprintf(
            'advanced-report-%s.%s',
            CarbonImmutable::now(Format::timezone())->toDateString(),
            $format->extension(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The files
    |--------------------------------------------------------------------------
    */

    /**
     * Streamed through the one `CsvWriter`, rows first, the "what this covers" block after them.
     *
     * With a UTF-8 byte-order mark, as the other CSVs meant for Excel write it: without one, Excel on
     * Windows reads the file as ANSI and the batch label's em dash (or any accented name) arrives
     * garbled.
     */
    public function csv(AdvancedReportFilters $filters, User $viewer, int $rows): StreamedResponse
    {
        return (new CsvWriter(withBom: true))->download(
            $this->filename(ExportFormat::Csv),
            $this->reports->exportHeaders($viewer),
            fn (): Generator => $this->tabular($filters, $viewer, $rows),
        );
    }

    /**
     * The same rows and the same closing block as the CSV, as a workbook whose money columns are
     * numbers.
     */
    public function excel(AdvancedReportFilters $filters, User $viewer, int $rows): BinaryFileResponse
    {
        $headers = $this->reports->exportHeaders($viewer);

        return (new XlsxWriter(self::TITLE))->download(
            $this->filename(ExportFormat::Excel),
            $headers,
            fn (): Generator => $this->tabular($filters, $viewer, $rows),
            array_values(array_intersect(self::MONEY_COLUMNS, $headers)),
        );
    }

    /**
     * An A4 landscape PDF of `admin.advanced-reports.pdf` — sixteen columns do not fit portrait.
     */
    public function pdf(AdvancedReportFilters $filters, User $viewer, int $rows): Response
    {
        $this->assertRemoteAccessIsOff();

        $html = $this->views->make('admin.advanced-reports.pdf', $this->document($filters, $viewer, $rows))->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');

        // DocumentPdfRenderer's belt and braces: whatever upstream set, this render fetches nothing,
        // runs no PHP and no script. Nothing in this report needs any of the three.
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('isJavascriptEnabled', false);

        // Embed only the glyphs used. Without it every file carries the whole DejaVu font — close to
        // a megabyte for a report of no rows — and this one is meant to be emailed.
        $pdf->setOption('isFontSubsettingEnabled', true);

        return new Response((string) $pdf->output(), 200, [
            'Content-Type' => ExportFormat::Pdf->mime(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->filename(ExportFormat::Pdf)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * What the printed sheet and the PDF render — one array for both, so the two cannot drift.
     *
     * The rows are held in memory here, which is what the PDF/print cap is for. Money is formatted
     * for a reader ('Rs 12,500.00', 'Rs 500.00 in advance'), not for a spreadsheet.
     *
     * @return array<string, mixed>
     */
    public function document(AdvancedReportFilters $filters, User $viewer, int $rows): array
    {
        $headers = $this->reports->exportHeaders($viewer);
        $canSeeMoney = $this->reports->canSeeMoney($viewer);

        return [
            'title' => self::TITLE,
            'subtitle' => self::SUBTITLE,
            'filters' => $filters,
            'criteria' => $filters->describe(),
            'headers' => $headers,
            'numericColumns' => array_values(array_intersect(self::NUMERIC_COLUMNS, $headers)),
            'rows' => iterator_to_array($this->reports->exportRows($filters, $viewer, true), false),
            'rowCount' => $rows,
            'summary' => $this->reports->summary($filters, $viewer),
            'canSeeMoney' => $canSeeMoney,
            'omitted' => $canSeeMoney ? [] : self::MONEY_COLUMNS,
            'generatedAt' => CarbonImmutable::now(),
            'generatedBy' => (string) $viewer->name,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The data rows, then the closing block.
     *
     * @return Generator<int, array<array-key, string>>
     */
    private function tabular(AdvancedReportFilters $filters, User $viewer, int $rows): Generator
    {
        foreach ($this->reports->exportRows($filters, $viewer) as $row) {
            yield $row;
        }

        foreach ($this->metadata($filters, $viewer, $rows) as $line) {
            yield $line;
        }
    }

    /**
     * What this file covered: the filters in words, the count, when and by whom, and — named, never
     * silently dropped — the columns this reader was not allowed to see.
     *
     * @return list<list<string>>
     */
    private function metadata(AdvancedReportFilters $filters, User $viewer, int $rows): array
    {
        $lines = [
            [],
            ['Report', self::TITLE.' — '.self::SUBTITLE],
        ];

        foreach ($filters->describe() as $label => $value) {
            $lines[] = [$label, $value];
        }

        $lines[] = ['Rows', (string) $rows];
        $lines[] = ['Generated at', Format::dateTime(CarbonImmutable::now())];
        $lines[] = ['Generated by', (string) $viewer->name];

        if (! $this->reports->canSeeMoney($viewer)) {
            $lines[] = ['Not included (no permission)', implode(', ', self::MONEY_COLUMNS)];
        }

        return $lines;
    }

    /**
     * `DocumentPdfRenderer::assertRemoteAccessIsOff()`'s refusal, for the same reason: with remote
     * access on, any URL that reached the HTML would be fetched by the server.
     */
    private function assertRemoteAccessIsOff(): void
    {
        if ((bool) config('dompdf.options.enable_remote', false)) {
            throw new RuntimeException(
                'dompdf is configured with remote file access enabled, so no Advanced Reports PDF is '
                .'rendered until `dompdf.options.enable_remote` is false. See INV-21-5.'
            );
        }
    }
}
