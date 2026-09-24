<?php

declare(strict_types=1);

namespace App\Services\Reporting\Exceptions;

use RuntimeException;

/**
 * An export was asked for above `reports.export_max_rows` (phase-19-23 §5.3, §6.20).
 *
 * **The message names the count and the limit**, because that is the difference between a refusal
 * somebody can act on and one they can only retry. "Too many rows" leaves a person guessing which
 * filter to tighten and by how much; "your filters match 412,900 rows and the limit is 200,000"
 * tells them they need to halve it, and usually which month to pick.
 */
final class ExportTooLargeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $rowCount = 0,
        public readonly int $limit = 0,
    ) {
        parent::__construct($message);
    }

    public static function for(string $report, int $rows, int $limit): self
    {
        return new self(
            sprintf(
                '%s matches %s rows with these filters, and an export may hold at most %s. Narrow '
                .'the date range or add a filter, then try again.',
                $report,
                number_format($rows),
                number_format($limit),
            ),
            $rows,
            $limit,
        );
    }
}
