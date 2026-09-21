<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a report or a document leaves the system (requirement §99, phase-13 §6.8).
 *
 * **`excel` is deliberately absent.** Phase 23 installs the package; declaring the case now would put a
 * format in every dropdown that nothing can produce, and a download that 500s is worse than a button
 * that is not there yet.
 */
enum ExportFormat: string
{
    use HasOptions;

    case Print = 'print';
    case Pdf = 'pdf';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Print => 'Print',
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Print => 'slate',
            self::Pdf => 'rose',
            self::Csv => 'emerald',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Print => 'text/html',
            self::Pdf => 'application/pdf',
            self::Csv => 'text/csv',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Print => 'html',
            self::Pdf => 'pdf',
            self::Csv => 'csv',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Print => 'printer',
            self::Pdf => 'document-text',
            self::Csv => 'table-cells',
        };
    }
}
