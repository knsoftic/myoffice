<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads for the pages list and the SEO audit (`pages.export`, `seo.export`).
 *
 * Every cell that a spreadsheet would read as a formula (`=`, `+`, `-`, `@`, tab, carriage return) is
 * prefixed with an apostrophe, so an editor-controlled title can never execute in the reader's Excel.
 */
trait StreamsCsv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    protected function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // UTF-8 BOM so Excel opens Urdu and accented text correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $cell): string => $this->csvCell($cell), $row));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csvCell(mixed $cell): string
    {
        $value = match (true) {
            $cell === null => '',
            is_bool($cell) => $cell ? 'yes' : 'no',
            $cell instanceof \BackedEnum => (string) $cell->value,
            $cell instanceof \DateTimeInterface => $cell->format('Y-m-d H:i:s'),
            is_scalar($cell) => (string) $cell,
            default => '',
        };

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
