<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What every report in this system returns (phase-13 §6.9, F-4.14).
 *
 * **One envelope, shipped here and consumed by phases 18, 19–23 and 24–25.** A second report shape
 * would mean a second exporter, a second print layout and a second set of rules about what `totals`
 * means — and the first thing to diverge would be whether a withheld money column is absent or blank.
 *
 * `meta` is not decoration. It names the **date column the figures were grouped on**, the filters in
 * force and the basis, so a screen, a CSV and a PDF built from the same result cannot disagree about
 * what they are showing — and a reader who prints one can tell six months later what it covered.
 */
final readonly class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows  the detail lines, already formatted as strings
     * @param  list<array<string, mixed>>  $groups  subtotals, when the report groups
     * @param  array<string, mixed>  $totals  the bottom line
     * @param  array<string, mixed>  $meta  date column, basis, filters, omitted sources
     */
    public function __construct(
        public array $rows = [],
        public array $groups = [],
        public array $totals = [],
        public array $meta = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->groups === [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * The column keys, taken from the first row. A report with no rows has no columns, which is why a
     * caller building a header asks the result rather than guessing.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->rows === [] ? [] : array_keys($this->rows[0]);
    }

    /**
     * Sources this report could not include because the reader may not see them.
     *
     * Naming them is the point: a partial total read as a full one is worse than a refusal, and the
     * screen prints this so nobody adds up a number that is quietly missing a third of its inputs.
     *
     * @return list<string>
     */
    public function omittedSources(): array
    {
        return array_values((array) ($this->meta['omitted_sources'] ?? []));
    }

    public function with(array $meta): self
    {
        return new self($this->rows, $this->groups, $this->totals, array_merge($this->meta, $meta));
    }
}
