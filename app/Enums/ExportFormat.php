<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a report or a document leaves the system (requirement §99, phase-13 §6.8, phase-19-23 §3.5).
 *
 * **`excel` is declared and still not always offered**, which is the same argument Phase 13 made
 * from the other side. Phase 13 left the case out because a format in a dropdown that nothing can
 * produce is a download that 500s. Phase 23 adds it because §3.5 says so — but the reason the case
 * was withheld has not gone away, so it is answered by {@see self::isAvailable()} instead: the case
 * exists, `mime()` and `extension()` are filled, and the format reaches a dropdown only when
 * `reports.excel_enabled` is on **and** a writer package is actually installed.
 *
 * Declaring it has a real benefit beyond the contract: a `report_exports` row written by an
 * installation that had the package can still be read, named and explained by one that does not.
 * A stored value the enum cannot cast is a row that throws on the index screen.
 */
enum ExportFormat: string
{
    use HasOptions;

    case Print = 'print';
    case Pdf = 'pdf';
    case Csv = 'csv';

    /** A single-sheet workbook. Offered only when {@see self::isAvailable()} says so. */
    case Excel = 'excel';

    public function label(): string
    {
        return match ($this) {
            self::Print => 'Print',
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
            self::Excel => 'Excel',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Print => 'slate',
            self::Pdf => 'rose',
            self::Csv => 'emerald',
            self::Excel => 'green',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Print => 'text/html',
            self::Pdf => 'application/pdf',
            self::Csv => 'text/csv',
            self::Excel => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Print => 'html',
            self::Pdf => 'pdf',
            self::Csv => 'csv',
            self::Excel => 'xlsx',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Print => 'printer',
            self::Pdf => 'document-text',
            self::Csv => 'table-cells',
            self::Excel => 'table-cells',
        };
    }

    /**
     * Can this installation actually produce this format right now?
     *
     * Every format but `excel` answers yes: print, PDF and CSV are built from packages this
     * application has always had. `excel` needs both the institute's switch and a writer package,
     * and **the package check is the half that matters** — a switch turned on by somebody who read
     * the settings screen must not produce a button that 500s.
     *
     * The class name is looked up rather than imported, because importing a class that may not be
     * installed is how an optional dependency becomes a required one.
     */
    public function isAvailable(): bool
    {
        if ($this !== self::Excel) {
            return true;
        }

        return (bool) setting('reports.excel_enabled', false)
            && (class_exists('Maatwebsite\\Excel\\Excel') || class_exists('OpenSpout\\Writer\\XLSX\\Writer'));
    }

    /**
     * The formats a dropdown may offer.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $format): bool => $format->isAvailable()));
    }

    /** A format that streams rows rather than rendering a page. */
    public function isTabular(): bool
    {
        return $this === self::Csv || $this === self::Excel;
    }
}
