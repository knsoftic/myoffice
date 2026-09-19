<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one streaming CSV writer (phase-05 §6.10, E12). Phase 13's and Phase 23's exports reuse it; nobody writes
 * a second one.
 *
 * **Streaming, never in memory.** Rows come from an iterable — typically `rowsFrom()`, a generator over
 * `lazyById()` (a `chunkById` walk) — and are written to the output one at a time, so a 20,000-row export holds
 * one chunk in memory, not the file.
 *
 * **Formula injection is escaped.** A cell whose text begins with `=`, `+`, `-`, `@`, a tab or a carriage
 * return is prefixed with a single quote, so a spreadsheet opens `=HYPERLINK(...)` as text instead of executing
 * it (test 83). The escape is applied to every cell, headers included, whatever the column.
 *
 * Cells are rendered as plain strings: an enum as its label when it has one, a date as ISO-8601, a boolean as
 * `Yes` / `No`, null as empty. Money arrives as a decimal string and is written untouched.
 */
final class CsvWriter
{
    /** Leading characters a spreadsheet would treat as a formula. */
    public const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly string $delimiter = ',',
        private readonly bool $withBom = false,
    ) {}

    /**
     * Escape one cell value.
     */
    public static function escape(mixed $value): string
    {
        $text = self::stringify($value);

        if ($text !== '' && in_array($text[0], self::FORMULA_TRIGGERS, true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * A download response that streams `$rows` under `$headers`.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>|Closure(): iterable<array<array-key, mixed>>  $rows
     */
    public function download(string $filename, array $headers, iterable|Closure $rows): StreamedResponse
    {
        $filename = $this->safeFilename($filename);

        return new StreamedResponse(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException('CsvWriter: the output stream could not be opened.');
            }

            try {
                $this->write($handle, $headers, $rows instanceof Closure ? $rows() : $rows);
            } finally {
                fclose($handle);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Write `$headers` then every row to an open stream; returns the number of data rows written.
     *
     * @param  resource  $handle
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    public function write($handle, array $headers, iterable $rows): int
    {
        if ($this->withBom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        if ($headers !== []) {
            $this->putRow($handle, $headers);
        }

        $count = 0;

        foreach ($rows as $row) {
            $this->putRow($handle, $row);
            $count++;
        }

        return $count;
    }

    /**
     * Write the CSV to a file path (a queued export on the private disk); returns the data rows written.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    public function toFile(string $absolutePath, array $headers, iterable $rows): int
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('CsvWriter: the directory [%s] could not be created.', $directory));
        }

        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('CsvWriter: [%s] could not be opened for writing.', $absolutePath));
        }

        try {
            return $this->write($handle, $headers, $rows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * A generator mapping every row of `$query` through `$map`, walked with `lazyById()` in chunks of `$chunk`.
     *
     * @template TRow
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @param  callable(TRow): array<array-key, mixed>  $map
     * @return Generator<int, array<array-key, mixed>>
     */
    public static function rowsFrom(EloquentBuilder|QueryBuilder $query, callable $map, int $chunk = 500, ?string $column = null, ?string $alias = null): Generator
    {
        foreach ($query->lazyById(max(1, $chunk), $column, $alias) as $row) {
            yield $map($row);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<array-key, mixed>  $row
     */
    private function putRow($handle, array $row): void
    {
        $cells = array_map(static fn (mixed $value): string => self::escape($value), array_values($row));

        fputcsv($handle, $cells, $this->delimiter, '"', '');
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof BackedEnum => method_exists($value, 'label') ? (string) $value->label() : (string) $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => '',
        };
    }

    private function safeFilename(string $filename): string
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        $filename = trim($filename, '-.');

        if ($filename === '') {
            $filename = 'export';
        }

        return str_ends_with(strtolower($filename), '.csv') ? $filename : $filename.'.csv';
    }
}
