<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * A small, hand-written single-sheet `.xlsx` writer (D177).
 *
 * **Why not a package.** `ExportFormat::Excel` is wired to OpenSpout or maatwebsite, and neither is
 * installed — which is why the report engine has never offered Excel. A workbook is a zip of six XML
 * parts; writing them is a few dozen lines, and a dependency for that would be one more thing to
 * keep patched for a feature that needs none of its reading, styling or formula support.
 *
 * **What it writes, and nothing more:** `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml`,
 * `xl/_rels/workbook.xml.rels`, `xl/styles.xml` and `xl/worksheets/sheet1.xml` — the minimum Excel
 * and LibreOffice both open without a repair prompt. One sheet, a bold frozen header row, columns
 * sized to what they hold.
 *
 * **Every string is an inline string (`t="inlineStr"`), so nothing is ever a formula.** A cell whose
 * text is `=HYPERLINK(...)` is stored as that text and shown as that text; there is no shared-string
 * table and no `<f>` element anywhere, so there is nothing a spreadsheet could evaluate. That is a
 * stronger guarantee than `CsvWriter`'s leading-quote escape, which is why text here is written
 * untouched. Every string is XML-escaped, scrubbed to valid UTF-8 and stripped of the control
 * characters XML 1.0 forbids — one stray byte in a student's name must not make the whole file
 * unreadable.
 *
 * **Numbers are numbers only when the caller says so.** A column named in `$numeric` whose cell is a
 * plain decimal string ('12500.00', '-500.00') is written as a numeric cell with a two-decimal
 * format, so the money columns add up in a spreadsheet; anything else in that column (a blank, a
 * caption) stays text. PHP ints and floats are numeric wherever they appear. A phone number or a
 * student code that happens to be all digits therefore stays text, leading zeros and all.
 *
 * **Streaming, never in memory.** Rows are written to a scratch file as they arrive from the
 * iterable (typically a generator over `lazy()`), and the sheet is assembled from that file, so a
 * 200,000-row export holds one row at a time, not the workbook.
 */
final class XlsxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Excel's own ceiling for one cell's text. */
    private const MAX_CELL_LENGTH = 32767;

    /** Column widths are clamped so one long email does not make a column a screen wide. */
    private const MIN_WIDTH = 8;

    private const MAX_WIDTH = 60;

    /** `xl/styles.xml` cellXfs indexes. */
    private const STYLE_DEFAULT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_DECIMAL = 2;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function __construct(
        private readonly string $sheetName = 'Report',
    ) {}

    /**
     * A download response for a workbook of `$headers` then every row of `$rows`.
     *
     * The workbook is written to a temporary file first — a zip cannot be streamed to the client as
     * it is built, because its central directory is written last — and the file is deleted once it
     * has been sent.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>|Closure(): iterable<array<array-key, mixed>>  $rows
     * @param  list<array-key>  $numeric  row keys whose plain-decimal values are written as numbers
     */
    public function download(string $filename, array $headers, iterable|Closure $rows, array $numeric = []): BinaryFileResponse
    {
        $path = $this->temporaryPath();

        try {
            $this->toFile($path, $headers, $rows instanceof Closure ? $rows() : $rows, $numeric);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw $exception;
        }

        return response()
            ->download($path, $this->safeFilename($filename), [
                'Content-Type' => self::MIME,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Write the workbook to `$absolutePath`; returns the number of data rows written (the header
     * row not counted).
     *
     * A row may be a label-keyed array (the cells are its values, in order) or a list. A row shorter
     * than the header is fine — a trailing note under the table is two cells wide.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>  $rows
     * @param  list<array-key>  $numeric
     */
    public function toFile(string $absolutePath, array $headers, iterable $rows, array $numeric = []): int
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('XlsxWriter: the directory [%s] could not be created.', $directory));
        }

        $rowsPath = $this->temporaryPath('xlsx-rows-');
        $sheetPath = $this->temporaryPath('xlsx-sheet-');

        try {
            [$count, $widths] = $this->writeRows($rowsPath, array_values($headers), $rows, array_flip($numeric));
            $this->writeSheet($sheetPath, $rowsPath, $widths, $headers !== []);
            $this->package($absolutePath, $sheetPath);
        } finally {
            foreach ([$rowsPath, $sheetPath] as $scratch) {
                if (is_file($scratch)) {
                    @unlink($scratch);
                }
            }
        }

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | The sheet
    |--------------------------------------------------------------------------
    */

    /**
     * Every `<row>` of `<sheetData>`, written to a scratch file, while tracking the widest text per
     * column so `<cols>` — which must come before the data — can be written afterwards.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>  $rows
     * @param  array<array-key, int>  $numeric
     * @return array{0: int, 1: array<int, int>}
     */
    private function writeRows(string $path, array $headers, iterable $rows, array $numeric): array
    {
        $handle = $this->open($path);
        $widths = [];
        $rowNumber = 0;
        $count = 0;

        try {
            if ($headers !== []) {
                $rowNumber++;
                $cells = '';

                foreach ($headers as $index => $header) {
                    $text = $this->text($header);
                    $widths[$index] = max($widths[$index] ?? 0, mb_strlen($text) + 2);
                    $cells .= $this->stringCell($index, $rowNumber, $text, self::STYLE_HEADER);
                }

                fwrite($handle, '<row r="'.$rowNumber.'">'.$cells.'</row>');
            }

            foreach ($rows as $row) {
                $rowNumber++;
                $count++;
                $cells = '';
                $index = 0;

                foreach ($row as $key => $value) {
                    $cells .= $this->cell($index, $rowNumber, $value, array_key_exists($key, $numeric), $widths);
                    $index++;
                }

                fwrite($handle, $cells === '' ? '<row r="'.$rowNumber.'"/>' : '<row r="'.$rowNumber.'">'.$cells.'</row>');
            }
        } finally {
            fclose($handle);
        }

        return [$count, $widths];
    }

    /**
     * One cell. A null is no cell at all — an absent `<c>` is how a sheet says "empty".
     *
     * @param  array<int, int>  $widths
     */
    private function cell(int $index, int $rowNumber, mixed $value, bool $numericColumn, array &$widths): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            $number = (string) $value;
            $widths[$index] = max($widths[$index] ?? 0, strlen($number) + 2);

            return '<c r="'.$this->reference($index, $rowNumber).'"><v>'.$number.'</v></c>';
        }

        $text = $this->text($value);

        if ($text === '') {
            return '';
        }

        $widths[$index] = max($widths[$index] ?? 0, mb_strlen($text) + 2);

        // Only a canonical decimal — no thousands separator, no currency, no exponent — so what is
        // written as a number is exactly the value the caller held.
        if ($numericColumn && preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $text) === 1) {
            return '<c r="'.$this->reference($index, $rowNumber).'" s="'.self::STYLE_DECIMAL.'"><v>'.$text.'</v></c>';
        }

        return $this->stringCell($index, $rowNumber, $text, self::STYLE_DEFAULT);
    }

    private function stringCell(int $index, int $rowNumber, string $text, int $style): string
    {
        return '<c r="'.$this->reference($index, $rowNumber).'" t="inlineStr"'
            .($style === self::STYLE_DEFAULT ? '' : ' s="'.$style.'"')
            .'><is><t xml:space="preserve">'.$this->escape($text).'</t></is></c>';
    }

    /**
     * `xl/worksheets/sheet1.xml`: the frozen header, the column widths, then the rows copied from the
     * scratch file in chunks.
     *
     * @param  array<int, int>  $widths
     */
    private function writeSheet(string $path, string $rowsPath, array $widths, bool $hasHeader): void
    {
        $handle = $this->open($path);

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n");
            fwrite($handle, '<worksheet xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_RELATIONSHIPS.'">');

            if ($hasHeader) {
                fwrite($handle, '<sheetViews><sheetView workbookViewId="0">'
                    .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                    .'<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
                    .'</sheetView></sheetViews>');
            }

            fwrite($handle, '<sheetFormatPr defaultRowHeight="15"/>');

            // `<cols>` may not be empty, so a sheet with no cells has none.
            if ($widths !== []) {
                ksort($widths);
                fwrite($handle, '<cols>');

                foreach ($widths as $index => $width) {
                    $column = $index + 1;
                    $width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
                    fwrite($handle, '<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>');
                }

                fwrite($handle, '</cols>');
            }

            fwrite($handle, '<sheetData>');

            $rows = fopen($rowsPath, 'rb');

            if ($rows === false) {
                throw new RuntimeException('XlsxWriter: the row scratch file could not be reopened.');
            }

            try {
                stream_copy_to_stream($rows, $handle);
            } finally {
                fclose($rows);
            }

            fwrite($handle, '</sheetData></worksheet>');
        } finally {
            fclose($handle);
        }
    }

    /**
     * The zip: five fixed parts and the sheet.
     */
    private function package(string $absolutePath, string $sheetPath): void
    {
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $zip = new ZipArchive;
        $opened = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException(sprintf('XlsxWriter: [%s] could not be created (zip error %s).', $absolutePath, (string) $opened));
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');

        // The sheet is read from disk here, at close() — which is why the scratch files are removed
        // only after this returns.
        if ($zip->close() !== true) {
            throw new RuntimeException(sprintf('XlsxWriter: [%s] could not be written.', $absolutePath));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The fixed parts
    |--------------------------------------------------------------------------
    */

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="'.self::NS_PACKAGE_RELATIONSHIPS.'">'
            .'<Relationship Id="rId1" Type="'.self::NS_RELATIONSHIPS.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_RELATIONSHIPS.'">'
            .'<sheets><sheet name="'.$this->escape($this->sheetTitle()).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="'.self::NS_PACKAGE_RELATIONSHIPS.'">'
            .'<Relationship Id="rId1" Type="'.self::NS_RELATIONSHIPS.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::NS_RELATIONSHIPS.'/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Three cell formats: the default, the bold header (`STYLE_HEADER`) and a two-decimal number with
     * a thousands separator (`STYLE_DECIMAL`, built-in format 4, `#,##0.00`) for money.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="'.self::NS_MAIN.'">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /*
    |--------------------------------------------------------------------------
    | Small helpers
    |--------------------------------------------------------------------------
    */

    /** 0 => 'A1'-style column letters: 0 = A, 25 = Z, 26 = AA. */
    private function reference(int $index, int $rowNumber): string
    {
        $letters = '';
        $number = $index + 1;

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $number = intdiv($number - 1, 26);
        }

        return $letters.$rowNumber;
    }

    /**
     * A cell value as text: valid UTF-8, no characters XML 1.0 forbids, at most Excel's cell length.
     */
    private function text(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof BackedEnum => method_exists($value, 'label') ? (string) $value->label() : (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => '',
        };

        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return mb_strlen($text) > self::MAX_CELL_LENGTH ? mb_substr($text, 0, self::MAX_CELL_LENGTH) : $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Excel's sheet-name rules: 1–31 characters, none of `[ ] : * ? / \`. */
    private function sheetTitle(): string
    {
        $title = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $this->text($this->sheetName)));

        return $title === '' ? 'Sheet1' : mb_substr($title, 0, 31);
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename), '-.');

        if ($filename === '') {
            $filename = 'export';
        }

        return str_ends_with(strtolower($filename), '.xlsx') ? $filename : $filename.'.xlsx';
    }

    private function temporaryPath(string $prefix = 'xlsx-'): string
    {
        return rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.$prefix.Str::random(24).'.tmp';
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('XlsxWriter: [%s] could not be opened for writing.', $path));
        }

        return $handle;
    }
}
