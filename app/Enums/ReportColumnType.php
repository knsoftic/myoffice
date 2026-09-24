<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What one report column holds (phase-19-23 §6.20).
 *
 * **The type is how the screen, the CSV and the PDF stay in agreement.** A figure right-aligned on
 * screen and left-aligned in the file is a report two people read differently; worse, a money value
 * formatted by the view but not by the exporter is a CSV whose totals no spreadsheet can add up. So
 * alignment and formatting are decided here, once, and every renderer asks rather than guesses.
 *
 * **`Money` is the one case that carries a permission consequence.** INV-23-2 says a money column a
 * viewer may not see is absent from the SELECT and from the file — not blanked, not zeroed. A blank
 * cell in a column somebody can still total is worse than a missing column, because the total looks
 * complete. {@see self::isFinancial()} is what the engine asks before it builds the query.
 */
enum ReportColumnType: string
{
    use HasOptions;

    case Text = 'text';
    case Number = 'number';

    /** A `decimal(15,2)` figure. Formatted through `money()`, and gated by `view_financial`. */
    case Money = 'money';

    /** A `decimal(8,4)` rate, rendered with a `%` suffix. */
    case Percent = 'percent';

    case Date = 'date';
    case DateTime = 'datetime';

    /** A status rendered as a coloured pill on screen and as its plain label in a file. */
    case Badge = 'badge';

    /** Text plus a route. The file keeps the text and drops the link — a CSV cannot be clicked. */
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Money => 'Money',
            self::Percent => 'Percentage',
            self::Date => 'Date',
            self::DateTime => 'Date and time',
            self::Badge => 'Status',
            self::Link => 'Link',
        };
    }

    /**
     * Does this column need the source module's `view_financial`?
     *
     * Only `Money`. A percentage is not money — a pass rate and an attendance percentage are both
     * `percent`, and gating them on a financial permission would hide half the institute's reports
     * from the people who run it. A commission *rate* that genuinely is financial says so through
     * its column's own `permission`, which is the per-column override this does not replace.
     */
    public function isFinancial(): bool
    {
        return $this === self::Money;
    }

    /**
     * Which way the column sits in a table.
     *
     * Numbers right so the digits line up; dates centred so a column of them reads as a column;
     * everything else left.
     */
    public function align(): string
    {
        return match ($this) {
            self::Number, self::Money, self::Percent => 'right',
            self::Date, self::DateTime, self::Badge => 'center',
            self::Text, self::Link => 'left',
        };
    }

    /** Can a total be taken down this column at all? */
    public function isSummable(): bool
    {
        return $this === self::Number || $this === self::Money;
    }

    /**
     * How many decimals this type is rendered with, or null when the question does not apply.
     *
     * Money is two because the column is `decimal(15,2)`; a percentage is two because four would
     * report a pass rate to a ten-thousandth of a student.
     */
    public function decimals(): ?int
    {
        return match ($this) {
            self::Money, self::Percent => 2,
            self::Number => 0,
            default => null,
        };
    }
}
